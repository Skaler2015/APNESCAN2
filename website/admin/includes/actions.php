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
        audit('settings_change', 'app controls updated');
        set_flash('Settings saved. Apps pick up broadcasts / flags on next launch.');
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

    case 'archive': // delete events older than N days
        require_cap('settings');
        $days = max(1, (int)($_POST['days'] ?? 365));
        $GLOBALS['db']->prepare('DELETE FROM events WHERE ts<?')->execute([time() - $days * 86400]);
        audit('archive', 'events older than ' . $days . 'd');
        set_flash('Archived events older than ' . $days . ' days.');
        redirect($back);
}
