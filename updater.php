<?php
declare(strict_types=1);

if (!defined('MEMOIR_VERSION')) {
    http_response_code(404);
    exit;
}

const MEMOIR_UPDATE_REPOSITORY = 'monirsaikat/memoir';
const MEMOIR_UPDATE_INTERVAL = 86400;
const MEMOIR_UPDATE_MANUAL_COOLDOWN = 60;

function memoir_update_directory(): string {
    return __DIR__ . '/storage/updates';
}

function memoir_update_state_file(): string {
    return memoir_update_directory() . '/state.json';
}

function memoir_ensure_update_directory(): void {
    $directory = memoir_update_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Memoir cannot create storage/updates. Check folder permissions.');
    }
}

function memoir_read_update_state(): array {
    $path = memoir_update_state_file();
    if (!is_file($path)) return [];
    $decoded = json_decode((string) @file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function memoir_write_update_state(array $state): void {
    memoir_ensure_update_directory();
    $path = memoir_update_state_file();
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Memoir could not save update status.');
    }
    @chmod($path, 0640);
}

function memoir_update_capabilities(): array {
    $issues = [];
    if (!extension_loaded('curl')) $issues[] = 'The PHP cURL extension is required.';
    if (!class_exists('ZipArchive')) $issues[] = 'The PHP ZIP extension is required.';
    if (is_dir(__DIR__ . '/.git')) $issues[] = 'Development checkout detected. Update this copy with Git instead.';

    try {
        memoir_ensure_update_directory();
        $probe = memoir_update_directory() . '/.write-test-' . bin2hex(random_bytes(3));
        if (@file_put_contents($probe, 'ok', LOCK_EX) === false) {
            $issues[] = 'storage/updates is not writable.';
        } else {
            @unlink($probe);
        }
    } catch (Throwable $error) {
        $issues[] = $error->getMessage();
    }

    foreach (['bootstrap.php', 'api.php', 'index.php', 'VERSION'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (is_file($path) && !is_writable($path)) {
            $issues[] = "$file is not writable by PHP.";
            break;
        }
    }

    return ['can_install' => !$issues, 'issues' => array_values(array_unique($issues))];
}

function memoir_http(string $url, array $headers = [], ?string $destination = null, int $timeout = 20): string {
    if (!extension_loaded('curl') || !str_starts_with($url, 'https://')) {
        throw new RuntimeException('A secure cURL connection is not available.');
    }

    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Could not start the update request.');
    $output = null;
    $file = null;
    try {
        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Memoir/' . MEMOIR_VERSION . ' (+' . 'https://github.com/' . MEMOIR_UPDATE_REPOSITORY . ')',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($destination !== null) {
            $file = @fopen($destination, 'wb');
            if (!$file) throw new RuntimeException('Could not create the temporary download.');
            $options[CURLOPT_FILE] = $file;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($handle, $options);
        $output = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($output === false || $status < 200 || $status >= 300) {
            $detail = curl_error($handle);
            throw new RuntimeException($detail ?: "GitHub returned HTTP $status.");
        }
        return $destination === null ? (string) $output : '';
    } finally {
        if (is_resource($file)) fclose($file);
        curl_close($handle);
    }
}

function memoir_normalize_version(string $tag): ?string {
    $version = ltrim(trim($tag), "vV");
    return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) ? $version : null;
}

function memoir_public_update_state(array $state): array {
    $capabilities = memoir_update_capabilities();
    $latest = isset($state['latest_version']) ? (string) $state['latest_version'] : null;
    return [
        'ok' => true,
        'current_version' => MEMOIR_VERSION,
        'latest_version' => $latest,
        'update_available' => $latest !== null && version_compare($latest, MEMOIR_VERSION, '>'),
        'release_name' => (string) ($state['release_name'] ?? ''),
        'release_notes' => (string) ($state['release_notes'] ?? ''),
        'release_url' => (string) ($state['release_url'] ?? ''),
        'published_at' => $state['published_at'] ?? null,
        'last_checked' => $state['last_checked'] ?? null,
        'error' => $state['error'] ?? null,
        'can_install' => $capabilities['can_install'],
        'install_issues' => $capabilities['issues'],
    ];
}

function memoir_check_for_updates(bool $manual = false): array {
    memoir_ensure_update_directory();
    $lockPath = memoir_update_directory() . '/check.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Could not lock the update checker.');

    try {
        $state = memoir_read_update_state();
        $now = time();
        if ($manual) {
            $lastManual = (int) ($state['last_manual_check_unix'] ?? 0);
            if ($lastManual > $now - MEMOIR_UPDATE_MANUAL_COOLDOWN) {
                return memoir_public_update_state($state) + ['cooldown' => MEMOIR_UPDATE_MANUAL_COOLDOWN - ($now - $lastManual)];
            }
            $state['last_manual_check_unix'] = $now;
        } elseif ((int) ($state['last_checked_unix'] ?? 0) > $now - MEMOIR_UPDATE_INTERVAL) {
            return memoir_public_update_state($state);
        }

        try {
            $json = memoir_http(
                'https://api.github.com/repos/' . MEMOIR_UPDATE_REPOSITORY . '/releases/latest',
                ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28']
            );
            $release = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            $version = memoir_normalize_version((string) ($release['tag_name'] ?? ''));
            if (!$version) throw new RuntimeException('The latest GitHub release has an invalid version tag.');
            $assetName = "memoir-v$version.zip";
            $checksumName = "$assetName.sha256";
            $asset = null;
            $checksum = null;
            foreach ((array) ($release['assets'] ?? []) as $candidate) {
                if (($candidate['name'] ?? '') === $assetName) $asset = $candidate;
                if (($candidate['name'] ?? '') === $checksumName) $checksum = $candidate;
            }
            if (!$asset || !$checksum) {
                throw new RuntimeException("Release $version is missing its verified install package.");
            }
            $state = array_merge($state, [
                'latest_version' => $version,
                'release_name' => mb_substr((string) ($release['name'] ?? "Memoir $version"), 0, 200),
                'release_notes' => mb_substr((string) ($release['body'] ?? ''), 0, 6000),
                'release_url' => (string) ($release['html_url'] ?? ''),
                'published_at' => $release['published_at'] ?? null,
                'asset_url' => (string) ($asset['browser_download_url'] ?? ''),
                'asset_digest' => (string) ($asset['digest'] ?? ''),
                'checksum_url' => (string) ($checksum['browser_download_url'] ?? ''),
                'last_checked' => gmdate('c'),
                'last_checked_unix' => $now,
                'error' => null,
            ]);
        } catch (Throwable $error) {
            $state['last_checked'] = gmdate('c');
            $state['last_checked_unix'] = $now;
            $state['error'] = mb_substr($error->getMessage(), 0, 300);
        }
        memoir_write_update_state($state);
        return memoir_public_update_state($state);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function memoir_safe_package_path(string $name): ?string {
    $name = str_replace('\\', '/', $name);
    if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) return null;
    $parts = explode('/', trim($name, '/'));
    if (!$parts || in_array('..', $parts, true) || in_array('.', $parts, true)) return null;
    if (array_shift($parts) !== 'memoir') return null;
    return implode('/', $parts);
}

function memoir_protected_update_path(string $relative): bool {
    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    return $relative === ''
        || $relative === 'config.php'
        || str_starts_with($relative, 'storage/')
        || str_starts_with($relative, 'uploads/')
        || str_starts_with($relative, '.git/')
        || str_starts_with($relative, '.github/');
}

function memoir_copy_file(string $source, string $target): void {
    $directory = dirname($target);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create ' . basename($directory) . '.');
    }
    $temporary = $target . '.memoir-update-' . bin2hex(random_bytes(3));
    if (!@copy($source, $temporary)) throw new RuntimeException('Could not stage ' . basename($target) . '.');
    @chmod($temporary, is_file($target) ? (fileperms($target) & 0777) : 0644);
    if (is_file($target) && !@unlink($target)) {
        @unlink($temporary);
        throw new RuntimeException('Could not replace ' . basename($target) . '.');
    }
    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        throw new RuntimeException('Could not activate ' . basename($target) . '.');
    }
}

function memoir_install_update(string $requestedVersion): array {
    $requestedVersion = memoir_normalize_version($requestedVersion) ?? '';
    $state = memoir_read_update_state();
    if ($requestedVersion === '' || ($state['latest_version'] ?? '') !== $requestedVersion || !version_compare($requestedVersion, MEMOIR_VERSION, '>')) {
        throw new RuntimeException('The selected update is no longer valid. Check again.');
    }
    $capabilities = memoir_update_capabilities();
    if (!$capabilities['can_install']) throw new RuntimeException(implode(' ', $capabilities['issues']));

    memoir_ensure_update_directory();
    $lock = @fopen(memoir_update_directory() . '/install.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another update is already running.');
    $run = memoir_update_directory() . '/run-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $package = $run . '/package.zip';
    $extract = $run . '/extract';
    $rollback = $run . '/rollback';
    $applied = [];

    try {
        if (!@mkdir($extract, 0750, true) || !@mkdir($rollback, 0750, true)) {
            throw new RuntimeException('Could not create the update workspace.');
        }
        memoir_http((string) $state['asset_url'], [], $package, 120);
        $checksumText = memoir_http((string) $state['checksum_url'], [], null, 20);
        if (!preg_match('/\b([a-f0-9]{64})\b/i', $checksumText, $match)) {
            throw new RuntimeException('The release checksum is invalid.');
        }
        $actualHash = hash_file('sha256', $package);
        if (!$actualHash || !hash_equals(strtolower($match[1]), strtolower($actualHash))) {
            throw new RuntimeException('The downloaded package failed SHA-256 verification.');
        }
        if (preg_match('/^sha256:([a-f0-9]{64})$/i', (string) ($state['asset_digest'] ?? ''), $digest)
            && !hash_equals(strtolower($digest[1]), strtolower($actualHash))) {
            throw new RuntimeException('The package does not match GitHub\'s asset digest.');
        }

        $zip = new ZipArchive();
        if ($zip->open($package) !== true) throw new RuntimeException('The update package is not a valid ZIP file.');
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = (string) ($stat['name'] ?? '');
            $relative = memoir_safe_package_path($name);
            if ($relative === null) {
                $zip->close();
                throw new RuntimeException('The package contains an unsafe path.');
            }
            if ($relative === '' || str_ends_with($name, '/')) continue;
            $mode = ((int) ($stat['external_attributes'] ?? 0) >> 16) & 0170000;
            if ($mode === 0120000 || memoir_protected_update_path($relative)) continue;
            if ((int) ($stat['size'] ?? 0) > 25 * 1024 * 1024) {
                $zip->close();
                throw new RuntimeException('The package contains an unexpectedly large file.');
            }
            $destination = $extract . '/' . $relative;
            if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0750, true) && !is_dir(dirname($destination))) {
                $zip->close();
                throw new RuntimeException('Could not extract the update.');
            }
            $input = $zip->getStream($name);
            $output = @fopen($destination, 'wb');
            if (!$input || !$output) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                $zip->close();
                throw new RuntimeException('Could not extract the update.');
            }
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            $files[] = $relative;
        }
        $zip->close();

        foreach (['VERSION', 'bootstrap.php', 'api.php', 'index.php', 'updater.php'] as $required) {
            if (!is_file($extract . '/' . $required)) throw new RuntimeException("The package is missing $required.");
        }
        if (trim((string) file_get_contents($extract . '/VERSION')) !== $requestedVersion) {
            throw new RuntimeException('The package version does not match the release tag.');
        }

        $workspaceBackup = write_workspace_backup('before-update-' . $requestedVersion);
        usort($files, static function (string $a, string $b): int {
            $late = ['api.php' => 1, 'bootstrap.php' => 2, 'VERSION' => 3];
            return ($late[$a] ?? 0) <=> ($late[$b] ?? 0) ?: strcmp($a, $b);
        });
        foreach ($files as $relative) {
            $target = __DIR__ . '/' . $relative;
            $existed = is_file($target);
            if ($existed) {
                $backup = $rollback . '/' . $relative;
                if (!is_dir(dirname($backup)) && !@mkdir(dirname($backup), 0750, true) && !is_dir(dirname($backup))) {
                    throw new RuntimeException('Could not prepare rollback files.');
                }
                if (!@copy($target, $backup)) throw new RuntimeException('Could not back up ' . $relative . '.');
            }
            $applied[] = ['path' => $relative, 'existed' => $existed];
            memoir_copy_file($extract . '/' . $relative, $target);
        }

        $state['installed_version'] = $requestedVersion;
        $state['installed_at'] = gmdate('c');
        $state['error'] = null;
        memoir_write_update_state($state);
        @file_put_contents($run . '/success.json', json_encode([
            'version' => $requestedVersion,
            'installed_at' => gmdate('c'),
            'workspace_backup' => $workspaceBackup,
            'files' => count($files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return ['ok' => true, 'version' => $requestedVersion, 'workspace_backup' => $workspaceBackup];
    } catch (Throwable $error) {
        foreach (array_reverse($applied) as $item) {
            $target = __DIR__ . '/' . $item['path'];
            if ($item['existed'] && is_file($rollback . '/' . $item['path'])) {
                @copy($rollback . '/' . $item['path'], $target);
            } elseif (!$item['existed'] && is_file($target)) {
                @unlink($target);
            }
        }
        throw $error;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
