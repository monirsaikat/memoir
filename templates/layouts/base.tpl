{*
    Base document shared by every Memoir page.

    Child templates start with {extends file='layouts/base.tpl'} and fill these blocks:

        htmlAttributes   extra attributes for <html> (start with a space)
        meta             <meta> tags that belong before <title>
        title            document title
        theme            head script that applies the saved theme; override empty for fixed-theme pages
        icons            favicon, web-app manifest, theme colour
        styles           page stylesheets, loaded after the shared web font
        bodyAttributes   attributes for <body> (start with a space)
        body             page content, including page scripts
*}
<!doctype html>
<html lang="en"{block name=htmlAttributes}{/block}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
{block name=meta}{/block}
    <title>{block name=title}Memoir{/block}</title>
{block name=theme}{include file='partials/theme-boot.tpl'}{/block}
{block name=icons}
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
{/block}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
{block name=styles}{/block}
</head>
<body{block name=bodyAttributes}{/block}>
{block name=body}{/block}
</body>
</html>
