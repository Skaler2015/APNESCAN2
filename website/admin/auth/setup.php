<?php
/**
 * ApneScan Admin — first-run setup wizard (runs only while api/config.php is
 * missing). Writes the MySQL credentials + bootstrap super-admin password.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);
require_once ADMIN_ROOT . '/components/head.php';

$DEF = ['host' => 'localhost', 'name' => 'u246829578_apnescan', 'user' => 'u246829578_apnescan'];
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = trim($_POST['host'] ?? 'localhost');
    $name = trim($_POST['name'] ?? '');
    $user = trim($_POST['user'] ?? '');
    $pass = (string)($_POST['pass'] ?? '');
    $adm  = (string)($_POST['admin'] ?? '');
    if ($name === '' || $user === '' || strlen($adm) < 6) {
        $err = 'Fill DB name/user and an admin password of at least 6 characters.';
    } else {
        try {
            new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $php = "<?php\n"
                 . '$DB_HOST = ' . var_export($host, true) . ";\n"
                 . '$DB_NAME = ' . var_export($name, true) . ";\n"
                 . '$DB_USER = ' . var_export($user, true) . ";\n"
                 . '$DB_PASS = ' . var_export($pass, true) . ";\n"
                 . '$ADMIN_HASH = ' . var_export(password_hash($adm, PASSWORD_DEFAULT), true) . ";\n";
            if (@file_put_contents(CONFIG_FILE, $php) === false) {
                $err = 'Could not write api/config.php — check folder permissions in File Manager.';
            } else { header('Location: admin.php'); exit; }
        } catch (Throwable $e) {
            $err = 'Could not connect to the database — check the password. (' . h($e->getMessage()) . ')';
        }
    }
}

page_head('Setup');
echo '<div class="authwrap"><form class="authbox" method="post">'
   . '<div class="brand"><span class="logo">A</span><span>ApneScan Admin<small>First-time setup</small></span></div>'
   . '<p class="mut" style="font-size:13px;margin-top:10px">Enter your MySQL password (from Hostinger) and choose an admin password for this dashboard.</p>'
   . ($err ? '<div class="flash err" style="margin-top:14px">' . h($err) . '</div>' : '')
   . '<label class="fl">MySQL host</label><input class="inp" name="host" value="' . h($DEF['host']) . '">'
   . '<label class="fl">Database name</label><input class="inp" name="name" value="' . h($DEF['name']) . '">'
   . '<label class="fl">Database user</label><input class="inp" name="user" value="' . h($DEF['user']) . '">'
   . '<label class="fl">Database password</label><input class="inp" type="password" name="pass" placeholder="MySQL password">'
   . '<label class="fl">New admin password</label><input class="inp" type="password" name="admin" placeholder="at least 6 characters">'
   . '<button class="bigbtn">Save &amp; continue</button>'
   . '<div class="foot">🔒 Credentials are written to a protected config file, never committed.</div>'
   . '</form></div>';
page_foot();
