{*
    Accept-a-collaboration-invite page, rendered by accept-invite.php.

    Extends layouts/auth.tpl. Branches: invalid/expired token; signed in as a
    different account than the one invited; an account for the invited email
    already exists (send them to log in); otherwise a signup form that creates
    the collaborator account and accepts the invite in one step.

    Variables: csrf, token, invite (array|null), accountExists (bool),
    signedInAsOther (bool), error (string).
*}
{extends file='layouts/auth.tpl'}

{block name=title}Accept invite — Memoir{/block}

{block name=card}
{if !$invite}
    <h1>Link expired</h1>
    <p>This invite link is invalid or has expired. Ask the note's owner to send a new one.</p>
    <div class="auth-foot"><a class="auth-link" href="login.php">Back to sign in</a></div>
{elseif $signedInAsOther}
    <h1>Wrong account</h1>
    {if $error}<div class="notice error">{$error}</div>{/if}
    <div class="auth-foot"><a class="auth-link" href="logout.php">Sign out and try again</a></div>
{elseif $accountExists}
    <h1>You're invited</h1>
    <p>{$invite.invited_email} already has a Memoir account. Sign in to accept access to "{$invite.note_title}".</p>
    <div class="auth-foot"><a class="auth-link" href="login.php">Sign in</a></div>
{else}
    <h1>You're invited</h1>
    <p>Create an account to start editing "{$invite.note_title}".</p>

{if $error}
    <div class="notice error">{$error}</div>
{/if}

    <form method="post">
        <input type="hidden" name="_csrf" value="{$csrf}">
        <input type="hidden" name="token" value="{$token}">

        <label for="email">Email</label>
        <input id="email" type="email" value="{$invite.invited_email}" disabled>

        <label for="name">Your name</label>
        <input id="name" type="text" name="name" autocomplete="name" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required>

        <label for="confirm">Confirm password</label>
        <input id="confirm" type="password" name="confirm" autocomplete="new-password" required>

        <button class="primary-btn" type="submit">Create account &amp; accept</button>
    </form>
{/if}
{/block}
