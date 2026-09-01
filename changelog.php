<?php

require __DIR__ . '/bootstrap.php';

$user = require_auth();

$settings = db()->query("SELECT * FROM settings WHERE id = 1")->fetch();

$changelogPath = __DIR__ . '/CHANGELOG.md';
$changeLogs = is_file($changelogPath) ? file_get_contents($changelogPath) : '';

function render_changelog_markdown(string $markdown): string
{
    if (trim($markdown) === '') {
        return '<div class="changelog-empty">No changelog entries found.</div>';
    }

    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

    // Collect reference links from the end of the Markdown file:
    // [1.7.0]: https://example.com
    $references = [];

    $markdown = preg_replace_callback(
        '/^\[([^\]]+)\]:\s*(https?:\/\/\S+)\s*$/mi',
        function ($matches) use (&$references) {
            $references[$matches[1]] = $matches[2];
            return '';
        },
        $markdown
    );

    $renderInline = static function (string $text) use (&$references): string {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Inline code
        $text = preg_replace(
            '/`([^`]+)`/',
            '<code>$1</code>',
            $text
        );

        // Standard Markdown links: [Label](https://example.com)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/',
            static function ($matches) {
                $label = $matches[1];
                $url = htmlspecialchars_decode($matches[2], ENT_QUOTES);

                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    return $matches[0];
                }

                return '<a href="' .
                    htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                    '" target="_blank" rel="noopener noreferrer">' .
                    $label .
                    '</a>';
            },
            $text
        );

        // Reference links: [1.7.0]
        $text = preg_replace_callback(
            '/\[([^\]]+)\]/',
            static function ($matches) use (&$references) {
                $label = htmlspecialchars_decode($matches[1], ENT_QUOTES);

                if (!isset($references[$label])) {
                    return $matches[0];
                }

                return '<a href="' .
                    htmlspecialchars($references[$label], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                    '" target="_blank" rel="noopener noreferrer">' .
                    htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
                    '</a>';
            },
            $text
        );

        return $text;
    };

    $lines = explode("\n", $markdown);
    $html = '';
    $paragraph = [];
    $inList = false;

    $flushParagraph = static function () use (&$paragraph, &$html, $renderInline): void {
        if (!$paragraph) {
            return;
        }

        $text = trim(implode(' ', $paragraph));

        if ($text !== '') {
            $html .= '<p>' . $renderInline($text) . '</p>';
        }

        $paragraph = [];
    };

    $closeList = static function () use (&$inList, &$html): void {
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flushParagraph();
            $closeList();
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();
            $closeList();

            $level = strlen($matches[1]);
            $title = $matches[2];

            if ($level === 1) {
                $html .= '<h1 class="changelog-title">' . $renderInline($title) . '</h1>';
            } elseif ($level === 2) {
                $plainTitle = strip_tags(htmlspecialchars_decode($renderInline($title), ENT_QUOTES));
                $id = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $plainTitle), '-'));

                $html .= '<section class="changelog-release" id="' .
                    htmlspecialchars($id, ENT_QUOTES, 'UTF-8') .
                    '">';
                $html .= '<h2>' . $renderInline($title) . '</h2>';
            } else {
                $html .= '<h3>' . $renderInline($title) . '</h3>';
            }

            continue;
        }

        if (preg_match('/^-\s+(.+)$/', $trimmed, $matches)) {
            $flushParagraph();

            if (!$inList) {
                $html .= '<ul>';
                $inList = true;
            }

            $html .= '<li>' . $renderInline($matches[1]) . '</li>';
            continue;
        }

        $paragraph[] = $trimmed;
    }

    $flushParagraph();
    $closeList();

    return $html;
}

$renderedChangeLogs = render_changelog_markdown($changeLogs);

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_token() ?>">

    <title>Changelog · <?= e($settings['app_name'] ?? 'Memoir') ?></title>

    <script>
        (function() {
            try {
                var choice = localStorage.getItem('memoir-theme') || 'system';

                var darkFlavors = {
                    dark: 1,
                    ocean: 1,
                    midnight: 1
                };

                if (choice === 'system') {
                    choice = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }

                if (!/^(light|dark|sepia|ocean|midnight)$/.test(choice)) {
                    choice = 'light';
                }

                document.documentElement.dataset.theme = choice;
                document.documentElement.dataset.mode = darkFlavors[choice] ? 'dark' : 'light';

                var accent = localStorage.getItem('memoir-accent');

                if (accent && /^#[0-9a-fA-F]{6}$/.test(accent)) {
                    document.documentElement.style.setProperty('--accent', accent);
                }
            } catch (e) {}
        })();
    </script>

    <link rel="icon" type="image/png" href="<?= asset('assets/img/favicon.png') ?>">
    <link rel="manifest" href="<?= asset('manifest.json') ?>">
    <meta name="theme-color" content="#6f5ee8">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">


    <style>
        body {
            font-family: "DM Sans", sans-serif;
        }

        .changelog-page {
            width: min(920px, 100%);
            margin: 0 auto;
            padding: 48px 24px 80px;
        }

        .changelog-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 42px;
        }

        .changelog-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: inherit;
            text-decoration: none;
        }

        .changelog-brand img {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            object-fit: contain;
        }

        .changelog-brand strong {
            font-size: 18px;
            font-weight: 700;
        }

        .changelog-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-muted, #6b7280);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }

        .changelog-back:hover {
            color: var(--accent, #6f5ee8);
        }

        .changelog-content {
            color: var(--text, #18181b);
        }

        .changelog-title {
            margin: 0 0 10px;
            font-size: clamp(34px, 6vw, 54px);
            line-height: 1.05;
            letter-spacing: -0.04em;
        }

        .changelog-content>p:first-of-type {
            margin: 0 0 44px;
            color: var(--text-muted, #71717a);
            font-size: 17px;
            line-height: 1.75;
        }

        .changelog-release {
            position: relative;
            padding: 34px 0 38px;
            border-top: 1px solid var(--border, rgba(127, 127, 127, 0.18));
        }

        .changelog-release h2 {
            margin: 0 0 24px;
            font-size: 25px;
            line-height: 1.25;
            letter-spacing: -0.025em;
        }

        .changelog-release h2 a {
            color: inherit;
            text-decoration: none;
        }

        .changelog-release h2 a:hover {
            color: var(--accent, #6f5ee8);
        }

        .changelog-content h3 {
            display: inline-flex;
            align-items: center;
            margin: 22px 0 10px;
            padding: 5px 10px;
            border-radius: 999px;
            background: color-mix(in srgb, var(--accent, #6f5ee8) 10%, transparent);
            color: var(--accent, #6f5ee8);
            font-size: 12px;
            line-height: 1;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .changelog-content ul {
            margin: 4px 0 0;
            padding: 0;
            list-style: none;
        }

        .changelog-content li {
            position: relative;
            margin: 0;
            padding: 9px 0 9px 23px;
            color: var(--text-muted, #52525b);
            font-size: 15px;
            line-height: 1.75;
        }

        .changelog-content li::before {
            content: "";
            position: absolute;
            top: 19px;
            left: 3px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent, #6f5ee8);
        }

        .changelog-content p {
            color: var(--text-muted, #52525b);
            font-size: 15px;
            line-height: 1.75;
        }

        .changelog-content a {
            color: var(--accent, #6f5ee8);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .changelog-content code {
            padding: 2px 6px;
            border: 1px solid var(--border, rgba(127, 127, 127, 0.18));
            border-radius: 6px;
            background: var(--surface-2, rgba(127, 127, 127, 0.08));
            color: inherit;
            font: 500 0.88em/1.4 ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }

        .changelog-empty {
            padding: 30px;
            border: 1px dashed var(--border, rgba(127, 127, 127, 0.25));
            border-radius: 14px;
            color: var(--text-muted, #71717a);
            text-align: center;
        }

        @media (max-width: 640px) {
            .changelog-page {
                padding: 28px 18px 60px;
            }

            .changelog-header {
                margin-bottom: 32px;
            }

            .changelog-title {
                font-size: 38px;
            }

            .changelog-release {
                padding: 28px 0 32px;
            }

            .changelog-release h2 {
                font-size: 21px;
            }
        }
    </style>
</head>

<body>

    <main class="changelog-page">
        <header class="changelog-header">
            <a class="changelog-brand" href="<?= asset('index.php') ?>">
                <img src="<?= asset('assets/img/favicon.png') ?>" alt="">
                <strong><?= e($settings['app_name'] ?? 'Memoir') ?></strong>
            </a>

            <a class="changelog-back" href="<?= asset('index.php') ?>">
                <i class="fa-solid fa-arrow-left"></i>
                Back to notes
            </a>
        </header>

        <article class="changelog-content">
            <?= $renderedChangeLogs ?>
        </article>
    </main>

    <script>
        window.MEMOIR = {
            csrf: document.querySelector('meta[name="csrf-token"]').content
        };
    </script>

    <script src="<?= asset('assets/js/app') ?>"></script>

    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('<?= asset('sw.js') ?>').catch(function() {});
        }
    </script>

</body>

</html>