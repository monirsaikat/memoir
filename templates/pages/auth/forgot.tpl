{*
    Forgot-password page, rendered by forgot.php.

    Asks for the account email and, after a POST, either confirms that a reset
    link is on its way or shows the error (rate limit, mail failure). The form
    is only shown while nothing has been sent yet. Extends layouts/auth.tpl.

    Variables:
        csrf   CSRF token for the hidden _csrf field
        sent   bool, true once the reset request was accepted
        error  error message, '' when there is none
*}
{extends file='layouts/auth.tpl'}

{block name=title}Reset password — Memoir{/block}

{block name=card}
    <h1>Reset your password</h1>
    <p>Enter your account email and we will send you a reset link.</p>

    {if $sent}
    <div class="notice success">If that address belongs to an account, a reset link is on its way. Check your inbox.</div>
    {/if}

    {if $error}
    <div class="notice error">{$error}</div>
    {/if}

    {if !$sent}
    <form method="post">
        <input type="hidden" name="_csrf" value="{$csrf}">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" autocomplete="username" required>

        <button class="primary-btn" type="submit">Send reset link</button>
    </form>
    {/if}

    <div class="auth-foot"><a class="auth-link" href="login.php">Back to sign in</a></div>
{/block}
