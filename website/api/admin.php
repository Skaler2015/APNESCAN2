<?php
// ApneScan — admin analytics dashboard (anonymous usage stats).
// First run: a setup wizard writes config.php (MySQL details + admin password).
// After that: log in with the admin password to view the dashboard.
session_start();
$CFG = __DIR__ . '/config.php';
$DEFAULTS = ['host' => 'localhost', 'name' => 'u246829578_apnescan', 'user' => 'u246829578_apnescan'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function page($title, $body) {
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
     . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title>'
     . '<style>'
     . ':root{--bg:#f6f4fc;--panel:#fff;--ink:#1c1830;--muted:#6a6580;--line:#e9e5f4;--accent:#6d28d9;--accent2:#8b5cf6;--good:#16a34a}'
     . '@media(prefers-color-scheme:dark){:root{--bg:#0f0d17;--panel:#17141f;--ink:#eee;--muted:#a39fb6;--line:#262233;--accent:#a78bfa;--accent2:#8b5cf6}}'
     . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,Segoe UI,Roboto,sans-serif}'
     . '.wrap{max-width:1080px;margin:0 auto;padding:24px 18px}'
     . 'h1{font-size:22px;margin:0}h2{font-size:15px;margin:26px 0 10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}'
     . '.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}'
     . '.brand{display:flex;align-items:center;gap:10px;font-weight:800}.logo{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:grid;place-items:center;color:#fff;font-weight:800}'
     . '.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-top:14px}'
     . '.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px}'
     . '.card .n{font-size:26px;font-weight:800}.card .l{color:var(--muted);font-size:12px;margin-top:2px}'
     . 'table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:12px;overflow:hidden;font-size:13px}'
     . 'th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-weight:600;background:color-mix(in srgb,var(--accent) 6%,transparent)}tr:last-child td{border-bottom:0}'
     . '.bar{height:10px;border-radius:6px;background:linear-gradient(90deg,var(--accent),var(--accent2))}'
     . '.grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:760px){.grid2{grid-template-columns:1fr}}'
     . 'form.box{max-width:420px;margin:8vh auto;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:26px}'
     . 'label{display:block;font-size:13px;font-weight:600;margin:12px 0 5px}input{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;background:var(--bg);color:var(--ink)}'
     . '.btn{margin-top:18px;width:100%;padding:11px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:700;font-size:15px;cursor:pointer}'
     . '.msg{background:#fde8e8;color:#a01919;padding:10px 12px;border-radius:9px;font-size:13px;margin-top:12px}'
     . '.mut{color:var(--muted);font-size:12.5px}a.logout{color:var(--accent);font-weight:600;text-decoration:none;font-size:13px}'
     . '</style></head><body><div class="wrap">' . $body . '</div></body></html>';
  exit;
}

function db_from_cfg() {
  global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
  return new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/* ---------- First-run setup wizard ---------- */
if (!file_exists($CFG)) {
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
        $test = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $hash = password_hash($adm, PASSWORD_DEFAULT);
        $php  = "<?php\n"
              . '$DB_HOST = ' . var_export($host, true) . ";\n"
              . '$DB_NAME = ' . var_export($name, true) . ";\n"
              . '$DB_USER = ' . var_export($user, true) . ";\n"
              . '$DB_PASS = ' . var_export($pass, true) . ";\n"
              . '$ADMIN_HASH = ' . var_export($hash, true) . ";\n";
        if (@file_put_contents($CFG, $php) === false) {
          $err = 'Could not write config.php — check folder permissions in File Manager.';
        } else {
          $_SESSION['ok'] = true;
          header('Location: admin.php'); exit;
        }
      } catch (Throwable $e) {
        $err = 'Could not connect to the database — check the password. (' . h($e->getMessage()) . ')';
      }
    }
  }
  $b = '<form class="box" method="post"><div class="brand"><span class="logo">A</span> ApneScan Admin — Setup</div>'
     . '<p class="mut" style="margin-top:10px">First-time setup. Enter your MySQL password (from Hostinger) and choose an admin password for this dashboard.</p>'
     . ($err ? '<div class="msg">' . $err . '</div>' : '')
     . '<label>MySQL host</label><input name="host" value="' . h($DEFAULTS['host']) . '">'
     . '<label>Database name</label><input name="name" value="' . h($DEFAULTS['name']) . '">'
     . '<label>Database user</label><input name="user" value="' . h($DEFAULTS['user']) . '">'
     . '<label>Database password</label><input name="pass" type="password" placeholder="MySQL password">'
     . '<label>New admin password (for this panel)</label><input name="admin" type="password" placeholder="at least 6 characters">'
     . '<button class="btn">Save &amp; continue</button></form>';
  page('ApneScan Admin — Setup', $b);
}

require $CFG; // $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS,$ADMIN_HASH

/* ---------- Auth ---------- */
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }

if (empty($_SESSION['auth'])) {
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($ADMIN_HASH) && $ADMIN_HASH !== '' && password_verify((string)($_POST['admin'] ?? ''), $ADMIN_HASH)) {
      $_SESSION['auth'] = true; header('Location: admin.php'); exit;
    }
    $err = 'Wrong password.';
  }
  $b = '<form class="box" method="post"><div class="brand"><span class="logo">A</span> ApneScan Admin</div>'
     . '<p class="mut" style="margin-top:10px">Enter the admin password.</p>'
     . ($err ? '<div class="msg">' . $err . '</div>' : '')
     . '<label>Admin password</label><input name="admin" type="password" autofocus>'
     . '<button class="btn">Log in</button></form>';
  page('ApneScan Admin — Login', $b);
}

/* ---------- Dashboard ---------- */
try { $db = db_from_cfg(); } catch (Throwable $e) { page('Error', '<div class="msg">DB connection failed: ' . h($e->getMessage()) . '</div>'); }
$db->exec('CREATE TABLE IF NOT EXISTS events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    install VARCHAR(40), event VARCHAR(40), version VARCHAR(20), os VARCHAR(40),
    cnt INT, iphash VARCHAR(16), ts INT, day CHAR(10),
    INDEX idx_ts (ts), INDEX idx_install (install)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$now = time();
function q1($db, $sql, $args = []) { $s = $db->prepare($sql); $s->execute($args); return $s->fetchColumn(); }
function qa($db, $sql, $args = []) { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC); }

$totalInstalls = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events');
$active7  = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 7*86400]);
$active30 = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 30*86400]);
$eventsToday = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$now - 86400]);
$events7  = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$now - 7*86400]);
$eventsAll = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events');

$byEvent   = qa($db, 'SELECT event, SUM(cnt) c FROM events GROUP BY event ORDER BY c DESC LIMIT 20');
$byVersion = qa($db, 'SELECT version, COUNT(DISTINCT install) u FROM events GROUP BY version ORDER BY u DESC LIMIT 12');
$byOs      = qa($db, 'SELECT os, COUNT(DISTINCT install) u FROM events GROUP BY os ORDER BY u DESC LIMIT 12');
$daily     = qa($db, 'SELECT day, COUNT(DISTINCT install) u, SUM(cnt) c FROM events WHERE ts>=? GROUP BY day ORDER BY day', [$now - 30*86400]);
$recent    = qa($db, 'SELECT day, ts, event, version, os, SUBSTR(install,1,8) inst FROM events ORDER BY id DESC LIMIT 60');

$maxEvent = 0; foreach ($byEvent as $r) { $maxEvent = max($maxEvent, (int)$r['c']); }
$maxDaily = 0; foreach ($daily as $r) { $maxDaily = max($maxDaily, (int)$r['c']); }

$rows = function($arr, $label, $valkey, $namekey, $max = 0) {
  $out = '<table><tr><th>' . h($label) . '</th><th style="width:55%"></th><th style="text-align:right">Count</th></tr>';
  foreach ($arr as $r) {
    $v = (int)$r[$valkey]; $w = $max > 0 ? max(2, round($v / $max * 100)) : 0;
    $out .= '<tr><td>' . h($r[$namekey] === '' ? '(unknown)' : $r[$namekey]) . '</td>'
          . '<td>' . ($max > 0 ? '<div class="bar" style="width:' . $w . '%"></div>' : '') . '</td>'
          . '<td style="text-align:right;font-variant-numeric:tabular-nums">' . number_format($v) . '</td></tr>';
  }
  if (!$arr) $out .= '<tr><td colspan="3" class="mut">No data yet.</td></tr>';
  return $out . '</table>';
};

// Daily activity bars
$dailyHtml = '<div style="display:flex;align-items:flex-end;gap:3px;height:90px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px">';
foreach ($daily as $r) {
  $hh = $maxDaily > 0 ? max(3, round((int)$r['c'] / $maxDaily * 66)) : 3;
  $dailyHtml .= '<div title="' . h($r['day']) . ': ' . (int)$r['c'] . ' events" style="flex:1;height:' . $hh . 'px;border-radius:4px;background:linear-gradient(180deg,var(--accent2),var(--accent))"></div>';
}
if (!$daily) $dailyHtml .= '<div class="mut">No activity in the last 30 days yet.</div>';
$dailyHtml .= '</div>';

$body = '<div class="top"><div class="brand"><span class="logo">A</span><span>ApneScan — Usage Analytics</span></div>'
      . '<a class="logout" href="?logout=1">Log out</a></div>'
      . '<div class="cards">'
      . '<div class="card"><div class="n">' . number_format($totalInstalls) . '</div><div class="l">Total installs</div></div>'
      . '<div class="card"><div class="n">' . number_format($active7) . '</div><div class="l">Active · 7 days</div></div>'
      . '<div class="card"><div class="n">' . number_format($active30) . '</div><div class="l">Active · 30 days</div></div>'
      . '<div class="card"><div class="n">' . number_format($eventsToday) . '</div><div class="l">Events · today</div></div>'
      . '<div class="card"><div class="n">' . number_format($events7) . '</div><div class="l">Events · 7 days</div></div>'
      . '<div class="card"><div class="n">' . number_format($eventsAll) . '</div><div class="l">Events · all time</div></div>'
      . '</div>'
      . '<h2>Daily activity (30 days)</h2>' . $dailyHtml
      . '<div class="grid2"><div><h2>Feature usage</h2>' . $rows($byEvent, 'Feature', 'c', 'event', $maxEvent) . '</div>'
      . '<div><h2>App versions</h2>' . $rows($byVersion, 'Version', 'u', 'version') . '</div></div>'
      . '<div class="grid2"><div><h2>Operating system</h2>' . $rows($byOs, 'OS', 'u', 'os') . '</div>'
      . '<div><h2>Recent events</h2><table><tr><th>When</th><th>Feature</th><th>Ver</th><th>Install</th></tr>';
foreach ($recent as $r) {
  $body .= '<tr><td class="mut">' . h(gmdate('d M H:i', (int)$r['ts'])) . '</td><td>' . h($r['event']) . '</td><td>' . h($r['version']) . '</td><td class="mut">' . h($r['inst']) . '</td></tr>';
}
if (!$recent) $body .= '<tr><td colspan="4" class="mut">No events yet — install the app and use it.</td></tr>';
$body .= '</table></div></div>'
      . '<p class="mut" style="margin-top:22px">Anonymous usage only — no document content, filenames or personal data is collected.</p>';

page('ApneScan Admin', $body);
