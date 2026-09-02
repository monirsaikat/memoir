{*
    Layout for the sign-in family of pages: login, forgot password, reset password.

    Child templates fill `card` with a heading, intro text, notices and the form.
    Everything else (theme, fonts, stylesheet, logo) is shared here.
*}
{extends file='layouts/base.tpl'}

{block name=styles}
    <link rel="stylesheet" href="{'assets/css/app.css'|asset}">
{/block}

{block name=bodyAttributes} class="auth-page"{/block}

{block name=body}
<main class="auth-card">
    <img class="auth-logo" src="assets/img/memoir-logo.png" alt="Memoir">
{block name=card}{/block}
</main>
{/block}
