{*
    Sign-in page, rendered by login.php.

    Extends layouts/auth.tpl and fills `card` with the welcome copy, the three
    optional notices (installation complete, password updated, sign-in error)
    and the email/password form. Variables: csrf, error, installed, passwordReset.
*}
{extends file='layouts/auth.tpl'}

{block name=title}Sign in — Memoir{/block}

{block name=card}
    <h1>Welcome to Memoir</h1>
    <p>Your notes, quietly kept on your own server.</p>

    {if $installed}
    <div class="notice success">Installation complete. Sign in to continue.</div>
    {/if}

    {if $passwordReset}
    <div class="notice success">Password updated. Sign in with your new password.</div>
    {/if}

    {if $error}
    <div class="notice error">{$error}</div>
    {/if}

    <form method="post">
        <input type="hidden" name="_csrf" value="{$csrf}">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="username" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>

        <button class="primary-btn" type="submit">Sign in</button>
    </form>

    <div class="auth-foot"><a class="auth-link" href="forgot.php">Forgot password?</a> · Memoir · Self-hosted personal notes</div>
{/block}
