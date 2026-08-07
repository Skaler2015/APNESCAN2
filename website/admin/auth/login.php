<?php
/**
 * ApneScan Admin — login. Rate-limited password step, optional TOTP 2FA step,
 * session regeneration and audit logging.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);
require_once ADMIN_ROOT . '/components/head.php';

$err = '';

/** Finalise a successful authentication. */
function complete_login(array $who): void {
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['username'] = $who['username'];
    $_SESSION['role'] = $who['role'];
    unset($_SESSION['pending2fa']);
    clear_login_fails();
    $GLOBALS['db']->prepare('UPDATE admin_users SET last_login=? WHERE username=?')->execute([time(), $who['username']]);
    audit('login', 'from ' . client_ip());
    header('Location: admin.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- Step 2: TOTP code ----
    if (!empty($_SESSION['pending2fa']) && isset($_POST['code'])) {
        $who = $_SESSION['pending2fa'];
        [$secret] = user_2fa($who['username']);
        if (totp_verify($secret, (string)$_POST['code'])) {
            complete_login($who);
        }
        audit('login_2fa_failed', 'user=' . substr($who['username'], 0, 30));
        $err = 'Invalid or expired code.';
    }
    // ---- Step 1: password ----
    elseif (login_blocked()) {
        $err = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $user = trim($_POST['username'] ?? 'admin');
        $pass = (string)($_POST['password'] ?? '');
        $who = verify_login($user, $pass);
        if ($who) {
            [$secret, $enabled] = user_2fa($who['username']);
            if ($enabled && $secret !== '') {
                $_SESSION['pending2fa'] = $who;   // require code before granting access
            } else {
                complete_login($who);
            }
        } else {
            record_login_fail();
            audit('login_failed', 'user=' . substr($user, 0, 30));
            $err = 'Wrong username or password.';
        }
    }
}

$needCode = !empty($_SESSION['pending2fa']);
page_head($needCode ? 'Two-factor' : 'Login');
echo '<div class="authwrap"><form class="authbox" method="post">'
   . '<div class="brand"><span class="logo">A</span><span>ApneScan Admin<small>' . ($needCode ? 'Two-factor authentication' : 'Usage analytics &amp; control') . '</small></span></div>';
if ($needCode) {
    echo '<p class="mut" style="font-size:13px;margin-top:10px">Enter the 6-digit code from your authenticator app.</p>'
       . ($err ? '<div class="flash err" style="margin-top:14px">' . h($err) . '</div>' : '')
       . '<label class="fl">Authentication code</label><input class="inp" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" placeholder="123456" autofocus>'
       . '<button class="bigbtn">Verify</button>'
       . '<div class="foot"><a href="?logout=1">Cancel</a></div>';
} else {
    echo '<p class="mut" style="font-size:13px;margin-top:10px">Sign in to your dashboard.</p>'
       . ($err ? '<div class="flash err" style="margin-top:14px">' . h($err) . '</div>' : '')
       . '<label class="fl">Username</label><input class="inp" name="username" value="admin" autocomplete="username">'
       . '<label class="fl">Password</label><input class="inp" type="password" name="password" autocomplete="current-password" autofocus>'
       . '<button class="bigbtn">Log in</button>'
       . '<div class="foot">🔒 Sessions are secured with HttpOnly, SameSite cookies.</div>';
}
echo '</form></div>';
page_foot();
