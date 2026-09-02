{*
    Choose-a-new-password page, rendered by reset.php.

    Extends layouts/auth.tpl and fills its `card` block. Two branches: when the
    reset token is unknown or expired (`linkValid` false) the card only offers a
    link to request a new one; otherwise it shows the new-password form with the
    token carried in a hidden field.

    Variables: csrf, token, linkValid (bool), error (string, '' when none).
*}
{extends file='layouts/auth.tpl'}

{block name=title}Choose a new password — Memoir{/block}

{block name=card}
{if !$linkValid}
    <h1>Link expired</h1>
    <p>This reset link is invalid or has expired. Reset links work for 45 minutes.</p>
    <div class="auth-foot"><a class="auth-link" href="forgot.php">Request a new link</a></div>
{else}
    <h1>Choose a new password</h1>
    <p>Pick a strong password of at least 12 characters.</p>

{if $error}
    <div class="notice error">{$error}</div>
{/if}

    <form method="post">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="hidden" name="token" value="{$token}">

        <label for="password">New password</label>
        <input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required>

        <label for="confirm">Confirm new password</label>
        <input id="confirm" type="password" name="confirm" autocomplete="new-password" required>

        <button class="primary-btn" type="submit">Set new password</button>
    </form>

    <div class="auth-foot"><a class="auth-link" href="login.php">Back to sign in</a></div>
{/if}
{/block}
