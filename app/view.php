<?php
declare(strict_types=1);

/**
 * Memoir view layer.
 *
 * Every HTML page Memoir serves is a Smarty template under templates/. The PHP
 * entry points (index.php, login.php, ...) gather data and hand it to render();
 * they never print markup themselves.
 *
 * Smarty is vendored under lib/smarty, so no Composer step is needed. Compiled
 * templates are written to storage/templates, which Apache never serves.
 *
 * Template conventions:
 *   - Every {$variable} is HTML-escaped automatically. Append `nofilter` only
 *     for markup that was sanitized before it reached the template.
 *   - {'assets/css/app.css'|asset}  version-stamped asset URL
 *   - {$value|json}                 JSON safe to embed inside a <script> block
 *   - {$datetime|date:'M j'}        PHP date() formatting of a MySQL DATETIME
 */

use Smarty\Smarty;

require_once dirname(__DIR__) . '/lib/smarty/libs/Smarty.class.php';

/**
 * Render a template to the browser.
 *
 * @param string               $template Path relative to templates/, e.g. 'pages/auth/login.tpl'.
 * @param array<string, mixed> $data     Variables the template can read.
 */
function render(string $template, array $data = []): void
{
    $page = view_engine()->createTemplate($template);
    $page->assign($data);
    $page->display();
}

/**
 * Version-stamped URL for a file in the application root, so browsers refetch
 * changed CSS/JS immediately. Returns the raw URL; the template escapes it.
 */
function asset_url(string $path): string
{
    $file = dirname(__DIR__) . '/' . $path;
    $version = is_file($file)
        ? (string) filemtime($file)
        : (defined('MEMOIR_VERSION') ? MEMOIR_VERSION : '0');
    return $path . '?v=' . $version;
}

/**
 * The shared, lazily configured Smarty instance.
 */
function view_engine(): Smarty
{
    static $smarty = null;
    if ($smarty instanceof Smarty) {
        return $smarty;
    }

    $smarty = new Smarty();
    $smarty->setTemplateDir(dirname(__DIR__) . '/templates');
    $smarty->setCompileDir(view_compile_dir());
    $smarty->setCaching(Smarty::CACHING_OFF);

    // Escape every {$variable} by default; templates opt out with `nofilter`.
    $smarty->setEscapeHtml(true);
    // A missing optional array key renders as an empty string, like PHP's `??`.
    $smarty->muteUndefinedOrNullWarnings();

    $smarty->registerPlugin(Smarty::PLUGIN_MODIFIER, 'asset', 'asset_url');
    $smarty->registerPlugin(Smarty::PLUGIN_MODIFIER, 'json', 'view_json');
    $smarty->registerPlugin(Smarty::PLUGIN_MODIFIER, 'date', 'view_date');

    return $smarty;
}

/**
 * JSON that is safe inside a <script> block: angle brackets, ampersands and
 * quotes are hex-encoded so note content can never close the script tag.
 */
function view_json(mixed $value): string
{
    return (string) json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

/**
 * Format a MySQL DATETIME string with PHP date() syntax, e.g. {$row.updated_at|date:'F j, Y'}.
 */
function view_date(mixed $value, string $format): string
{
    return date($format, strtotime((string) $value) ?: 0);
}

/**
 * Directory for compiled templates.
 *
 * storage/ is preferred because Apache already denies access to it. If it
 * cannot be written to (for example while the installer is still explaining
 * that problem to the user) fall back to the system temp directory so the
 * page can still render.
 */
function view_compile_dir(): string
{
    $preferred = dirname(__DIR__) . '/storage/templates';
    if ((is_dir($preferred) || @mkdir($preferred, 0750, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = sys_get_temp_dir() . '/memoir-templates-' . md5(dirname(__DIR__));
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0700, true);
    }
    return $fallback;
}
