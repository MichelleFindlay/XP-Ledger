<?php

/**
 * One-time helper to connect a Nintendo Account (see lib/Nintendo.php).
 *
 * Nintendo has no API keys to copy, so this walks through the same sign-in
 * Nintendo's own app uses: you sign in on Nintendo's site, copy the link
 * from the "Select this account" button, paste it back here, and get a
 * session token (valid about two years) to put in config.php as
 * nintendo_session_token.
 *
 * Works from the command line (php nintendo-link.php) or in a browser.
 * It never stores anything on the server: the sign-in's one-time PKCE
 * values travel in the form itself, and the resulting token is only shown
 * to whoever completed the sign-in with their own Nintendo account — so
 * leaving this file reachable doesn't expose anything. Delete it after use
 * if you'd rather not have it there.
 */

require __DIR__ . '/lib/Nintendo.php';

$copyHelp = 'On the "Select this account" page, don\'t click the button. Right-click it and choose "Copy link address" '
    . '(or open the browser console and run: copy(document.getElementById(\'authorize-switch-approval-link\').href)). '
    . 'The link starts with ' . Nintendo::REDIRECT_URI . '#';

// --- Command line ---

if (PHP_SAPI === 'cli') {
    $start = Nintendo::startLink();
    fwrite(STDOUT, "1) Open this link in your browser and sign in to your Nintendo Account:\n\n" . $start['url'] . "\n\n");
    fwrite(STDOUT, '2) ' . $copyHelp . "\n\n3) Paste the link here and press Enter:\n> ");
    $link = (string) fgets(STDIN);
    $result = Nintendo::exchangeLink($link, $start['verifier'], $start['state']);
    if ($result['error']) {
        fwrite(STDERR, "\n" . $result['error'] . "\n");
        exit(1);
    }
    fwrite(STDOUT, "\nDone. Add this to config.php (it's valid for about two years — keep it secret):\n\n    'nintendo_session_token' => '" . $result['session_token'] . "',\n");
    exit(0);
}

// --- Browser ---

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$error = null;
$token = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = Nintendo::exchangeLink((string) ($_POST['link'] ?? ''), (string) ($_POST['verifier'] ?? ''), (string) ($_POST['state'] ?? ''));
    $error = $result['error'];
    $token = $result['session_token'];
}

// A failed attempt starts over with a fresh link: Nintendo's codes are
// single-use anyway.
$start = $token ? null : Nintendo::startLink();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Connect Nintendo · XP Ledger</title>
<style>
    body { margin: 0; padding: 40px 20px; background: #06080d; color: #f5f5f7; font: 15px/1.55 -apple-system, "Segoe UI", Roboto, sans-serif; }
    main { max-width: 640px; margin: 0 auto; }
    h1 { font-size: 1.4rem; letter-spacing: 0.04em; }
    ol { padding-left: 1.2em; }
    li { margin-bottom: 14px; }
    a { color: #ff5a5f; }
    .btn { display: inline-block; padding: 9px 16px; background: #e60012; color: #fff; border: 0; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
    input[type=text], textarea { width: 100%; box-sizing: border-box; padding: 10px; background: #0f121a; color: inherit; border: 1px solid #333; font: 13px ui-monospace, monospace; }
    textarea { height: 7em; }
    .error { padding: 12px 14px; background: rgba(230, 0, 18, 0.18); border: 1px solid rgba(230, 0, 18, 0.5); margin-bottom: 18px; }
    .muted { color: rgba(245, 245, 247, 0.6); font-size: 0.85rem; }
    code { background: #0f121a; padding: 1px 5px; }
</style>
</head>
<body>
<main>
    <h1>Connect your Nintendo Account</h1>

    <?php if ($token): ?>
        <p>Connected. Add this line to <code>config.php</code>, then reload the dashboard:</p>
        <textarea readonly onclick="this.select()">'nintendo_session_token' => '<?= h($token) ?>',</textarea>
        <p class="muted">This token signs in as you and lasts about two years — keep it secret, like a password.
            Nothing was saved on the server; once you close this page, it's only in your <code>config.php</code>.</p>
    <?php else: ?>
        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <ol>
            <li><a class="btn" href="<?= h($start['url']) ?>" target="_blank" rel="noopener noreferrer">Sign in to Nintendo</a>
                <div class="muted">Opens Nintendo's own sign-in page in a new tab. It will say it's linking to "Nintendo Store" — that's the app whose sign-in this uses.</div></li>
            <li><?= h($copyHelp) ?></li>
            <li>
                <form method="post">
                    <input type="hidden" name="verifier" value="<?= h($start['verifier']) ?>">
                    <input type="hidden" name="state" value="<?= h($start['state']) ?>">
                    <input type="text" name="link" placeholder="<?= h(Nintendo::REDIRECT_URI) ?>#session_state=…" autocomplete="off" required>
                    <p><button class="btn" type="submit">Get session token</button></p>
                </form>
                <div class="muted">Do steps 2 and 3 within a few minutes — the link only works once.</div>
            </li>
        </ol>
    <?php endif; ?>
</main>
</body>
</html>
