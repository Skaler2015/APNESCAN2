<?php
/**
 * ApneScan Admin — central POST action dispatcher. Runs BEFORE any HTML output
 * so it can redirect (Post/Redirect/Get). Every action is CSRF-checked,
 * capability-gated and audit-logged.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) return;
require_csrf();
$action = (string)$_POST['action'];
$back = $_POST['back'] ?? ('admin.php?page=' . ($_GET['page'] ?? 'dashboard'));

switch ($action) {

    case 'savecfg': // broadcast / remote-control / summary settings
        require_cap('settings');
        $cur = getset();
        $newMsg = trim((string)($_POST['message'] ?? ''));
        if ($newMsg !== ($cur['message'] ?? '')) setset('message_id', (string)(((int)($cur['message_id'] ?? 0)) + 1));
        setset('message', $newMsg);
        setset('message_type', in_array($_POST['message_type'] ?? 'info', ['info', 'warning', 'success'], true) ? $_POST['message_type'] : 'info');
        setset('min_version', preg_replace('/[^0-9.]/', '', (string)($_POST['min_version'] ?? '')));
        setset('force_update', isset($_POST['force_update']) ? '1' : '0');
        setset('download_url', trim((string)($_POST['download_url'] ?? '')));
        $flags = trim((string)($_POST['flags'] ?? '')); $dec = json_decode($flags === '' ? '{}' : $flags, true);
        setset('flags', is_array($dec) ? json_encode($dec) : '{}');
        setset('admin_email', trim((string)($_POST['admin_email'] ?? '')));
        if (($_POST['cron_token'] ?? '') !== '') setset('cron_token', preg_replace('/[^a-zA-Z0-9]/', '', (string)$_POST['cron_token']));
        setset('retention_days', (string)max(0, (int)($_POST['retention_days'] ?? 0)));
        // SMTP
        foreach (['smtp_host', 'smtp_user', 'smtp_from', 'smtp_from_name'] as $k) setset($k, trim((string)($_POST[$k] ?? '')));
        setset('smtp_port', (string)max(1, (int)($_POST['smtp_port'] ?? 587)));
        setset('smtp_secure', in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_secure'] : 'tls');
        if (($_POST['smtp_pass'] ?? '') !== '') setset('smtp_pass', (string)$_POST['smtp_pass']); // keep existing if blank
        audit('settings_change', 'app controls updated');
        set_flash('Settings saved. Apps pick up broadcasts / flags on next launch.');
        redirect($back);

    case 'test_email':
        require_cap('settings');
        require_once SITE_ROOT . '/api/mailer.php';
        $to = trim((string)($_POST['to'] ?? '')) ?: setting('admin_email', '');
        if ($to === '') { set_flash('Set a daily-summary email or a test recipient first.', 'err'); redirect($back); }
        [$ok, $detail] = apnescan_send_mail(getset(), $to, 'ApneScan test email', "This is a test email from your ApneScan admin dashboard.\nIf you received it, email delivery is working.");
        audit('test_email', $to . ' — ' . $detail);
        set_flash($ok ? 'Test email sent to ' . $to . ' (' . $detail . ').' : 'Could not send: ' . $detail, $ok ? 'ok' : 'err');
        redirect($back);

    case 'gen_api_key':
        require_cap('settings');
        setset('api_key', bin2hex(random_bytes(20)));
        audit('api_key', 'generated');
        set_flash('New API key generated.');
        redirect($back);

    case 'revoke_api_key':
        require_cap('settings');
        setset('api_key', '');
        audit('api_key', 'revoked');
        set_flash('API key revoked.');
        redirect($back);

    case 'passwd': // change own password
        $old = (string)($_POST['old'] ?? ''); $n1 = (string)($_POST['n1'] ?? ''); $n2 = (string)($_POST['n2'] ?? '');
        $u = current_user();
        if (!verify_login($u['username'], $old)) { set_flash('Current password is wrong.', 'err'); redirect($back); }
        if (strlen($n1) < 6 || $n1 !== $n2) { set_flash('New passwords must match and be 6+ characters.', 'err'); redirect($back); }
        if ($u['username'] === 'admin' && $u['role'] === 'super_admin') {
            global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
            $php = "<?php\n" . '$DB_HOST = ' . var_export($DB_HOST, true) . ";\n" . '$DB_NAME = ' . var_export($DB_NAME, true) . ";\n"
                 . '$DB_USER = ' . var_export($DB_USER, true) . ";\n" . '$DB_PASS = ' . var_export($DB_PASS, true) . ";\n"
                 . '$ADMIN_HASH = ' . var_export(password_hash($n1, PASSWORD_DEFAULT), true) . ";\n";
            @file_put_contents(CONFIG_FILE, $php);
        } else {
            $GLOBALS['db']->prepare('UPDATE admin_users SET pass_hash=? WHERE username=?')->execute([password_hash($n1, PASSWORD_DEFAULT), $u['username']]);
        }
        audit('password_change');
        set_flash('Password changed.');
        redirect($back);

    case 'fbseen':
        require_cap('view');
        $GLOBALS['db']->prepare('UPDATE feedback SET seen=1 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        redirect($back);

    case 'fbdel':
        require_cap('controls');
        $GLOBALS['db']->prepare('DELETE FROM feedback WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        audit('feedback_delete', 'id=' . (int)($_POST['id'] ?? 0));
        set_flash('Feedback deleted.');
        redirect($back);

    case 'user_add':
        require_cap('users');
        $un = preg_replace('/[^a-zA-Z0-9_.\-]/', '', (string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        $rl = in_array($_POST['role'] ?? 'viewer', array_keys($GLOBALS['ROLE_CAPS']), true) ? $_POST['role'] : 'viewer';
        if ($un === '' || strlen($pw) < 6) { set_flash('Username and a 6+ char password required.', 'err'); redirect($back); }
        $GLOBALS['db']->prepare('INSERT INTO admin_users (username,pass_hash,role,created) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pass_hash=VALUES(pass_hash), role=VALUES(role)')
            ->execute([$un, password_hash($pw, PASSWORD_DEFAULT), $rl, time()]);
        audit('user_add', $un . ' (' . $rl . ')');
        set_flash('Admin “' . $un . '” saved.');
        redirect($back);

    case 'user_del':
        require_cap('users');
        $un = (string)($_POST['username'] ?? '');
        $GLOBALS['db']->prepare('DELETE FROM admin_users WHERE username=?')->execute([$un]);
        audit('user_del', $un);
        set_flash('Admin removed.');
        redirect($back);

    case '2fa_begin': // start 2FA setup — generate a secret held in the session
        $_SESSION['setup_totp'] = totp_secret();
        redirect($back);

    case '2fa_enable':
        $secret = $_SESSION['setup_totp'] ?? '';
        if ($secret === '' || !totp_verify($secret, (string)($_POST['code'] ?? ''))) {
            set_flash('That code did not match — try again.', 'err'); redirect($back);
        }
        set_user_2fa(current_user()['username'], $secret, true);
        unset($_SESSION['setup_totp']);
        audit('2fa_enable');
        set_flash('Two-factor authentication is now ON.');
        redirect($back);

    case '2fa_disable':
        set_user_2fa(current_user()['username'], '', false);
        unset($_SESSION['setup_totp']);
        audit('2fa_disable');
        set_flash('Two-factor authentication disabled.');
        redirect($back);

    case 'backup_now':
        require_cap('backup');
        $name = write_backup_file($GLOBALS['db']);
        audit('backup', 'saved ' . $name);
        set_flash('Backup saved: ' . $name);
        redirect($back);

    case 'archive': // delete events older than N days
        require_cap('settings');
        $days = max(1, (int)($_POST['days'] ?? 365));
        $GLOBALS['db']->prepare('DELETE FROM events WHERE ts<?')->execute([time() - $days * 86400]);
        cache_flush();
        audit('archive', 'events older than ' . $days . 'd');
        set_flash('Archived events older than ' . $days . ' days.');
        redirect($back);
}
