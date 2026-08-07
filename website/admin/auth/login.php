<?php
/**
 * ApneScan Admin — login screen + handler. Rate-limited, regenerates the
 * session id on success, and records an audit entry.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);
require_once ADMIN_ROOT . '/components/head.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (login_blocked()) {
        $err = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $user = trim($_POST['username'] ?? 'admin');
        $pass = (string)($_POST['password'] ?? '');
        $who = verify_login($user, $pass);
        if ($who) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['username'] = $who['username'];
            $_SESSION['role'] = $who['role'];
            clear_login_fails();
            // touch last_login for real users
            $GLOBALS['db']->prepare('UPDATE admin_users SET last_login=? WHERE username=?')->execute([time(), $who['username']]);
            audit('login', 'from ' . client_ip());
            header('Location: admin.php'); exit;
        }
        record_login_fail();
        audit('login_failed', 'user=' . substr($user, 0, 30));
        $err = 'Wrong username or password.';
    }
}

page_head('Login');
echo '<div class="authwrap"><form class="authbox" method="post">'
   . '<div class="brand"><span class="logo">A</span><span>ApneScan Admin<small>Usage analytics &amp; control</small></span></div>'
   . '<p class="mut" style="font-size:13px;margin-top:10px">Sign in to your dashboard.</p>'
   . ($err ? '<div class="flash err" style="margin-top:14px">' . h($err) . '</div>' : '')
   . '<label class="fl">Username</label><input class="inp" name="username" value="admin" autocomplete="username">'
   . '<label class="fl">Password</label><input class="inp" type="password" name="password" autocomplete="current-password" autofocus>'
   . '<button class="bigbtn">Log in</button>'
   . '<div class="foot">🔒 Sessions are secured with HttpOnly, SameSite cookies.</div>'
   . '</form></div>';
page_foot();
