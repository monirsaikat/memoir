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

render('pages/changelog.tpl', [
    'csrf' => csrf_token(),
    'appName' => $settings['app_name'] ?? 'Memoir',
    'changelogHtml' => $renderedChangeLogs,
]);
