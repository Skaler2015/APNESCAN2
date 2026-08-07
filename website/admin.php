<?php
// ApneScan — admin analytics + control dashboard (anonymous usage stats).
// First run: a setup wizard writes api/config.php (MySQL + admin password).
// After that: log in with the admin password to view the dashboard, manage
// broadcasts / force-update / feature flags, and read user feedback.
session_start();
$CFG = __DIR__ . '/api/config.php';
@is_dir(__DIR__ . '/api') || @mkdir(__DIR__ . '/api', 0755, true);
$DEFAULTS = ['host' => 'localhost', 'name' => 'u246829578_apnescan', 'user' => 'u246829578_apnescan'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n) { return number_format((float)$n); }
function flag($cc) {
  if (!preg_match('/^[A-Za-z]{2}$/', (string)$cc)) return '🏳️';
  $cc = strtoupper($cc);
  return mb_chr(127397 + ord($cc[0]), 'UTF-8') . mb_chr(127397 + ord($cc[1]), 'UTF-8');
}

/* ---------- Shared page shell + design system ---------- */
function page($title, $body, $extraHead = '') {
  $css = <<<'CSS'
:root{--bg:#f3f0fb;--bg2:#ece6f8;--panel:#fff;--panel2:#faf8ff;--ink:#191426;--muted:#6d6785;--faint:#9a94ad;--line:#e9e4f4;
  --brand:#6d28d9;--brand2:#9333ea;--accent:#8b5cf6;--good:#16a34a;--warn:#d97706;--bad:#dc2626;
  --shadow:0 1px 2px rgba(24,16,54,.04),0 8px 24px rgba(24,16,54,.06);--shadow-lg:0 8px 40px rgba(76,29,149,.10)}
@media(prefers-color-scheme:dark){:root{--bg:#0c0a13;--bg2:#0f0c18;--panel:#161120;--panel2:#1b1526;--ink:#f1eef8;
  --muted:#a49dba;--faint:#726b87;--line:#251f34;--brand:#a78bfa;--brand2:#c084fc;--accent:#8b5cf6;
  --shadow:0 1px 2px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.35);--shadow-lg:0 8px 40px rgba(0,0,0,.5)}}
:root[data-theme=light]{color-scheme:light}
:root[data-theme=dark]{--bg:#0c0a13;--bg2:#0f0c18;--panel:#161120;--panel2:#1b1526;--ink:#f1eef8;--muted:#a49dba;
  --faint:#726b87;--line:#251f34;--brand:#a78bfa;--brand2:#c084fc;--accent:#8b5cf6;color-scheme:dark}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;color:var(--ink);font-family:Inter,system-ui,'Segoe UI',Roboto,sans-serif;min-height:100vh;-webkit-font-smoothing:antialiased;
  background:radial-gradient(1200px 600px at 80% -10%,color-mix(in srgb,var(--brand) 12%,transparent),transparent 60%),linear-gradient(180deg,var(--bg),var(--bg2))}
.wrap{max-width:1180px;margin:0 auto;padding:0 20px 60px}
h1,h2,h3{font-family:'Bricolage Grotesque',Inter,system-ui,sans-serif;letter-spacing:-.01em}
a{color:var(--brand)}.mut{color:var(--muted);font-size:12.5px}.faint{color:var(--faint)}
.bar{position:sticky;top:0;z-index:20;backdrop-filter:saturate(1.4) blur(14px);background:color-mix(in srgb,var(--bg) 82%,transparent);border-bottom:1px solid var(--line)}
.bar .in{max-width:1180px;margin:0 auto;padding:13px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:11px;font-weight:800;font-size:16px;font-family:'Bricolage Grotesque',sans-serif}
.logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));display:grid;place-items:center;color:#fff;font-weight:800;box-shadow:0 4px 14px color-mix(in srgb,var(--brand) 45%,transparent)}
.brand small{display:block;font-size:11px;font-weight:600;color:var(--muted);font-family:Inter,sans-serif;margin-top:1px}
.spacer{flex:1}
.chips{display:flex;gap:4px;background:var(--panel);border:1px solid var(--line);border-radius:11px;padding:4px;box-shadow:var(--shadow)}
.chip{padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted);text-decoration:none;white-space:nowrap}
.chip:hover{color:var(--ink);background:var(--panel2)}
.chip.on{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand2));box-shadow:0 3px 10px color-mix(in srgb,var(--brand) 40%,transparent)}
.ic{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:var(--panel);color:var(--muted);cursor:pointer;text-decoration:none;box-shadow:var(--shadow)}
.ic:hover{color:var(--brand);border-color:color-mix(in srgb,var(--brand) 40%,var(--line))}
.ic.live.on{color:#fff;background:linear-gradient(135deg,var(--good),#22c55e);border-color:transparent}
.ic svg{width:18px;height:18px}
.online{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:20px;background:color-mix(in srgb,var(--good) 12%,transparent);color:var(--good);font-weight:700;font-size:12.5px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--good);box-shadow:0 0 0 0 color-mix(in srgb,var(--good) 60%,transparent);animation:pulse 2s infinite}
@keyframes pulse{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--good) 55%,transparent)}70%{box-shadow:0 0 0 8px transparent}100%{box-shadow:0 0 0 0 transparent}}
@media(prefers-reduced-motion:reduce){.dot{animation:none}}
h2.sec{font-size:12px;margin:30px 0 12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;font-weight:700;font-family:Inter,sans-serif;display:flex;align-items:center;gap:8px}
h2.sec svg{width:15px;height:15px;opacity:.8}
.grid{display:grid;gap:16px}
.kpis{grid-template-columns:repeat(auto-fill,minmax(172px,1fr))}
.two{grid-template-columns:1.4fr 1fr}.two-eq{grid-template-columns:1fr 1fr}
@media(max-width:820px){.two,.two-eq{grid-template-columns:1fr}}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}
.pad{padding:18px 20px}
.ptitle{font-size:13.5px;font-weight:700;margin:0 0 2px;display:flex;align-items:center;gap:8px}.ptitle svg{width:16px;height:16px;color:var(--brand)}
.psub{font-size:12px;color:var(--faint);margin:0 0 14px}
.kpi{position:relative;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:16px 17px;box-shadow:var(--shadow);overflow:hidden}
.kpi::after{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand2))}
.kpi .row{display:flex;align-items:center;justify-content:space-between}
.kpi .bo{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:color-mix(in srgb,var(--brand) 12%,transparent);color:var(--brand)}
.kpi .bo svg{width:17px;height:17px}
.kpi .n{font-size:29px;font-weight:800;line-height:1.05;margin-top:12px;font-variant-numeric:tabular-nums;font-family:'Bricolage Grotesque',sans-serif}
.kpi .l{color:var(--muted);font-size:12.5px;margin-top:3px;font-weight:500}
.delta{font-size:11.5px;font-weight:700;padding:2px 7px;border-radius:20px}
.delta.up{color:var(--good);background:color-mix(in srgb,var(--good) 14%,transparent)}
.delta.down{color:var(--bad);background:color-mix(in srgb,var(--bad) 14%,transparent)}
.delta.flat{color:var(--faint);background:color-mix(in srgb,var(--faint) 14%,transparent)}
.blist{display:flex;flex-direction:column;gap:11px}
.brow{display:grid;grid-template-columns:150px 1fr auto;align-items:center;gap:12px}
.bname{font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btrack{height:9px;border-radius:20px;background:color-mix(in srgb,var(--brand) 9%,transparent);overflow:hidden}
.bfill{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--brand),var(--accent))}
.bval{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--muted);min-width:34px;text-align:right}
.tag{font-size:10.5px;font-weight:700;color:var(--brand);background:color-mix(in srgb,var(--brand) 12%,transparent);padding:1px 6px;border-radius:6px;margin-left:6px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--line)}
th{color:var(--faint);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
tbody tr:last-child td{border-bottom:0}tbody tr:hover{background:var(--panel2)}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
.pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:color-mix(in srgb,var(--accent) 14%,transparent);color:var(--brand)}
.mono{font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:11.5px;color:var(--muted)}
a.mono{color:var(--brand);text-decoration:none}a.mono:hover{text-decoration:underline}
.funnel{display:flex;flex-direction:column;gap:10px}
.fstep{display:grid;grid-template-columns:110px 1fr auto;align-items:center;gap:12px}
.fbar{height:34px;border-radius:9px;background:linear-gradient(90deg,var(--brand),var(--accent));display:flex;align-items:center;padding:0 12px;color:#fff;font-weight:700;font-size:13px;min-width:44px}
.fpct{font-size:12.5px;font-weight:700;color:var(--muted);min-width:42px;text-align:right}
.heat{display:grid;grid-template-columns:repeat(24,1fr);gap:4px}
.hc{aspect-ratio:1;border-radius:5px}
.hlabels{display:grid;grid-template-columns:repeat(24,1fr);gap:4px;margin-top:6px;font-size:9px;color:var(--faint);text-align:center}
.search{display:flex;gap:8px;margin:0 0 12px;flex-wrap:wrap}
.search input,.search select{padding:9px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:12.5px;background:var(--panel2);color:var(--ink)}
.search input{flex:1;min-width:140px}
.search input:focus,.search select:focus{outline:none;border-color:var(--brand)}
.sbtn{padding:9px 14px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.sbtn.ghost{background:var(--panel2);color:var(--muted);border:1px solid var(--line)}
label{display:block;font-size:12.5px;font-weight:600;margin:14px 0 6px}
input.f,select.f,textarea.f{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:13px;background:var(--panel2);color:var(--ink)}
textarea.f{min-height:76px;resize:vertical}
input.f:focus,select.f:focus,textarea.f:focus{outline:none;border-color:var(--brand)}
.chkrow{display:flex;align-items:center;gap:9px;margin-top:14px;font-size:13px;font-weight:600}
.chkrow input{width:17px;height:17px;accent-color:var(--brand)}
.savebtn{margin-top:16px;padding:10px 18px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:13.5px;cursor:pointer}
.okmsg{background:color-mix(in srgb,var(--good) 13%,transparent);color:var(--good);padding:10px 13px;border-radius:10px;font-size:13px;margin:0 0 16px;font-weight:600}
.fbitem{padding:14px 16px;border-bottom:1px solid var(--line)}
.fbitem:last-child{border-bottom:0}
.fbmeta{display:flex;gap:10px;align-items:center;font-size:11.5px;color:var(--faint);margin-bottom:5px;flex-wrap:wrap}
.fbmsg{font-size:13.5px;line-height:1.5}
.newdot{width:7px;height:7px;border-radius:50%;background:var(--brand);display:inline-block}
.xbtn{border:1px solid var(--line);background:var(--panel);color:var(--faint);border-radius:7px;padding:3px 9px;font-size:11px;cursor:pointer;font-weight:600}
.xbtn:hover{color:var(--bad);border-color:var(--bad)}
form.box{max-width:430px;margin:9vh auto;background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:30px;box-shadow:var(--shadow-lg)}
form.box .brand{font-size:18px;margin-bottom:6px}
.btn{margin-top:20px;width:100%;padding:12px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:15px;cursor:pointer;box-shadow:0 6px 18px color-mix(in srgb,var(--brand) 40%,transparent)}
.msg{background:color-mix(in srgb,var(--bad) 12%,transparent);color:var(--bad);padding:11px 13px;border-radius:10px;font-size:13px;margin-top:14px}
.foot{text-align:center;margin-top:34px;color:var(--faint);font-size:12px}
.empty{padding:26px;text-align:center;color:var(--faint);font-size:13px}
.back{display:inline-flex;align-items:center;gap:6px;color:var(--brand);text-decoration:none;font-weight:600;font-size:13px;margin:22px 0 4px}
CSS;
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
     . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . '</title>'
     . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
     . '<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
     . '<style>' . $css . '</style>' . $extraHead . '</head><body>' . $body . '</body></html>';
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
          $err = 'Could not write api/config.php — check folder permissions in File Manager.';
        } else { $_SESSION['ok'] = true; header('Location: admin.php'); exit; }
      } catch (Throwable $e) {
        $err = 'Could not connect to the database — check the password. (' . h($e->getMessage()) . ')';
      }
    }
  }
  $b = '<div class="wrap"><form class="box" method="post"><div class="brand"><span class="logo">A</span><span>ApneScan Admin<small>First-time setup</small></span></div>'
     . '<p class="mut" style="margin-top:12px">Enter your MySQL password (from Hostinger) and choose an admin password for this dashboard.</p>'
     . ($err ? '<div class="msg">' . $err . '</div>' : '')
     . '<label>MySQL host</label><input class="f" name="host" value="' . h($DEFAULTS['host']) . '">'
     . '<label>Database name</label><input class="f" name="name" value="' . h($DEFAULTS['name']) . '">'
     . '<label>Database user</label><input class="f" name="user" value="' . h($DEFAULTS['user']) . '">'
     . '<label>Database password</label><input class="f" name="pass" type="password" placeholder="MySQL password">'
     . '<label>New admin password (for this panel)</label><input class="f" name="admin" type="password" placeholder="at least 6 characters">'
     . '<button class="btn">Save &amp; continue</button></form></div>';
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
  $b = '<div class="wrap"><form class="box" method="post"><div class="brand"><span class="logo">A</span><span>ApneScan Admin<small>Usage analytics</small></span></div>'
     . '<p class="mut" style="margin-top:12px">Enter the admin password to view the dashboard.</p>'
     . ($err ? '<div class="msg">' . $err . '</div>' : '')
     . '<label>Admin password</label><input class="f" name="admin" type="password" autofocus>'
     . '<button class="btn">Log in</button></form></div>';
  page('ApneScan Admin — Login', $b);
}

/* ---------- DB + schema ---------- */
try { $db = db_from_cfg(); }
catch (Throwable $e) { page('Error', '<div class="wrap"><div class="msg" style="margin-top:40px">DB connection failed: ' . h($e->getMessage()) . '</div></div>'); }
$db->exec('CREATE TABLE IF NOT EXISTS events (id BIGINT AUTO_INCREMENT PRIMARY KEY,install VARCHAR(40),event VARCHAR(40),version VARCHAR(20),os VARCHAR(40),cnt INT,iphash VARCHAR(16),ts INT,day CHAR(10),INDEX idx_ts(ts),INDEX idx_install(install),INDEX idx_event(event)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE IF NOT EXISTS live (install VARCHAR(40) PRIMARY KEY, ts INT, version VARCHAR(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE IF NOT EXISTS geo (install VARCHAR(40) PRIMARY KEY, country CHAR(2), ts INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('CREATE TABLE IF NOT EXISTS feedback (id BIGINT AUTO_INCREMENT PRIMARY KEY,install VARCHAR(40),version VARCHAR(20),contact VARCHAR(120),message TEXT,ts INT,day CHAR(10),seen TINYINT DEFAULT 0,INDEX idx_ts(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

function q1($db, $sql, $a = []) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function qa($db, $sql, $a = []) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
function getset($db) { return $db->query('SELECT k,v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
function setset($db, $k, $v) { $s = $db->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)'); $s->execute([$k, $v]); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$flash = '';

/* ---------- POST actions (controls) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('bad token'); }
  $act = $_POST['action'];
  if ($act === 'savecfg') {
    $cur = getset($db);
    $newMsg = trim((string)($_POST['message'] ?? ''));
    if ($newMsg !== ($cur['message'] ?? '')) setset($db, 'message_id', (string)(((int)($cur['message_id'] ?? 0)) + 1));
    setset($db, 'message', $newMsg);
    setset($db, 'message_type', in_array($_POST['message_type'] ?? 'info', ['info', 'warning', 'success']) ? $_POST['message_type'] : 'info');
    setset($db, 'min_version', preg_replace('/[^0-9.]/', '', (string)($_POST['min_version'] ?? '')));
    setset($db, 'force_update', isset($_POST['force_update']) ? '1' : '0');
    setset($db, 'download_url', trim((string)($_POST['download_url'] ?? '')));
    $flags = trim((string)($_POST['flags'] ?? ''));
    $dec = json_decode($flags === '' ? '{}' : $flags, true);
    setset($db, 'flags', is_array($dec) ? json_encode($dec) : '{}');
    setset($db, 'admin_email', trim((string)($_POST['admin_email'] ?? '')));
    if (($_POST['cron_token'] ?? '') !== '') setset($db, 'cron_token', preg_replace('/[^a-zA-Z0-9]/', '', (string)$_POST['cron_token']));
    $flash = 'Settings saved. The app will pick up broadcasts / flags on its next launch.';
  } elseif ($act === 'passwd') {
    $old = (string)($_POST['old'] ?? ''); $n1 = (string)($_POST['n1'] ?? ''); $n2 = (string)($_POST['n2'] ?? '');
    if (!password_verify($old, $ADMIN_HASH)) $flash = '⚠ Current password is wrong.';
    elseif (strlen($n1) < 6 || $n1 !== $n2) $flash = '⚠ New passwords must match and be 6+ characters.';
    else {
      $php = "<?php\n" . '$DB_HOST = ' . var_export($DB_HOST, true) . ";\n" . '$DB_NAME = ' . var_export($DB_NAME, true) . ";\n"
           . '$DB_USER = ' . var_export($DB_USER, true) . ";\n" . '$DB_PASS = ' . var_export($DB_PASS, true) . ";\n"
           . '$ADMIN_HASH = ' . var_export(password_hash($n1, PASSWORD_DEFAULT), true) . ";\n";
      @file_put_contents($CFG, $php); $flash = '✓ Admin password changed.';
    }
  } elseif ($act === 'fbseen') { $s = $db->prepare('UPDATE feedback SET seen=1 WHERE id=?'); $s->execute([(int)($_POST['id'] ?? 0)]); $flash = 'Marked read.'; }
  elseif ($act === 'fbdel') { $s = $db->prepare('DELETE FROM feedback WHERE id=?'); $s->execute([(int)($_POST['id'] ?? 0)]); $flash = 'Feedback deleted.'; }
}

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="apnescan-events.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['datetime_utc', 'event', 'version', 'os', 'count', 'install']);
  foreach ($db->query('SELECT ts,event,version,os,cnt,install FROM events ORDER BY id DESC LIMIT 100000') as $r)
    fputcsv($out, [gmdate('Y-m-d H:i:s', (int)$r['ts']), $r['event'], $r['version'], $r['os'], $r['cnt'], substr($r['install'], 0, 10)]);
  fclose($out); exit;
}

/* ---------- Icons ---------- */
function ico($name) {
  $p = [
    'users'=>'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'pulse'=>'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>','spark'=>'<path d="M5 3v4M3 5h4M6 17v4m-2-2h4"/><path d="M13 3l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5z"/>',
    'calendar'=>'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>','repeat'=>'<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    'layers'=>'<path d="M12 2l10 6-10 6L2 8z"/><path d="M2 12l10 6 10-6M2 16l10 6 10-6"/>','chart'=>'<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'trend'=>'<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>','tag'=>'<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/>',
    'monitor'=>'<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>','clock'=>'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'list'=>'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>','crown'=>'<path d="M2 20h20M4 6l4 6 4-8 4 8 4-6v10H4z"/>',
    'filter'=>'<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>','globe'=>'<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
    'funnel'=>'<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>','alert'=>'<path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>',
    'msg'=>'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>','flag'=>'<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
    'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'download'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>','refresh'=>'<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.65 4.36A9 9 0 0 0 20.5 15"/>',
    'moon'=>'<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>','logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    'search'=>'<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>','key'=>'<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3"/>',
    'back'=>'<path d="M19 12H5M12 19l-7-7 7-7"/>',
  ];
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($p[$name] ?? '') . '</svg>';
}
function kpi($icon, $n, $label, $badge = '') {
  return '<div class="kpi"><div class="row"><span class="bo">' . ico($icon) . '</span>' . $badge . '</div><div class="n">' . $n . '</div><div class="l">' . h($label) . '</div></div>';
}
function areaChart($series, $k, $stroke, $fillId) {
  $n = count($series);
  if ($n < 2) return '<div class="empty">Not enough data yet — chart appears once there are a couple of days of activity.</div>';
  $w = 960; $hh = 210; $pad = 10; $base = $hh - 22; $max = 1;
  foreach ($series as $s) $max = max($max, (int)$s[$k]);
  $stepX = ($w - 2 * $pad) / ($n - 1); $pts = [];
  foreach ($series as $i => $s) { $x = $pad + $i * $stepX; $y = $base - ((int)$s[$k] / $max) * ($base - $pad); $pts[] = [round($x, 1), round($y, 1)]; }
  $line = 'M ' . $pts[0][0] . ' ' . $pts[0][1];
  for ($i = 1; $i < $n; $i++) $line .= ' L ' . $pts[$i][0] . ' ' . $pts[$i][1];
  $area = $line . ' L ' . $pts[$n - 1][0] . ' ' . $base . ' L ' . $pts[0][0] . ' ' . $base . ' Z'; $last = $pts[$n - 1]; $grid = '';
  for ($g = 1; $g <= 3; $g++) { $gy = round($pad + ($base - $pad) * $g / 4, 1); $grid .= '<line x1="' . $pad . '" y1="' . $gy . '" x2="' . ($w - $pad) . '" y2="' . $gy . '" stroke="currentColor" stroke-width="1" opacity=".08"/>'; }
  return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" preserveAspectRatio="none" style="width:100%;height:200px;color:var(--ink)"><defs><linearGradient id="' . $fillId . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $stroke . '" stop-opacity=".28"/><stop offset="1" stop-color="' . $stroke . '" stop-opacity="0"/></linearGradient></defs>' . $grid . '<path d="' . $area . '" fill="url(#' . $fillId . ')"/><path d="' . $line . '" fill="none" stroke="' . $stroke . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" fill="' . $stroke . '"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="8" fill="' . $stroke . '" opacity=".22"/></svg>';
}
function barlist($rows, $namek, $valk, $total = 0, $adoptk = null, $prefix = '') {
  if (!$rows) return '<div class="empty">No data in this range yet.</div>';
  $max = 0; foreach ($rows as $r) $max = max($max, (int)$r[$valk]);
  $out = '<div class="blist">';
  foreach ($rows as $r) {
    $v = (int)$r[$valk]; $wd = $max > 0 ? max(3, round($v / $max * 100)) : 0;
    $name = $r[$namek] === '' ? '(unknown)' : $r[$namek];
    $ad = ($adoptk !== null && $total > 0) ? ' <span class="tag">' . round((int)$r[$adoptk] / $total * 100) . '%</span>' : '';
    $out .= '<div class="brow"><div class="bname" title="' . h($name) . '">' . $prefix . h($name) . $ad . '</div><div class="btrack"><div class="bfill" style="width:' . $wd . '%"></div></div><div class="bval">' . nf($v) . '</div></div>';
  }
  return $out . '</div>';
}

$now = time();
$SET = getset($db);

/* ---------- Drill-down: single install ---------- */
if (isset($_GET['install'])) {
  $inst = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string)$_GET['install']), 0, 40);
  $sum = qa($db, 'SELECT MIN(ts) first, MAX(ts) last, SUM(cnt) events, COUNT(DISTINCT day) days, MAX(version) ver FROM events WHERE install=?', [$inst]);
  $s0 = $sum[0] ?? ['first' => 0, 'last' => 0, 'events' => 0, 'days' => 0, 'ver' => ''];
  $cc = (string) q1($db, 'SELECT country FROM geo WHERE install=?', [$inst]);
  $feat = qa($db, 'SELECT event, SUM(cnt) c FROM events WHERE install=? GROUP BY event ORDER BY c DESC LIMIT 20', [$inst]);
  $hist = qa($db, 'SELECT ts,event,version FROM events WHERE install=? ORDER BY id DESC LIMIT 100', [$inst]);
  $body = '<div class="bar"><div class="in"><div class="brand"><span class="logo">A</span><span>ApneScan<small>Install detail</small></span></div><div class="spacer"></div><a class="ic" href="admin.php" title="Back">' . ico('back') . '</a></div></div><div class="wrap">';
  $body .= '<a class="back" href="admin.php">' . ico('back') . 'Back to dashboard</a>';
  $body .= '<h2 class="sec">' . ico('users') . 'Install ' . h(substr($inst, 0, 12)) . ' ' . flag($cc) . '</h2>';
  $body .= '<div class="grid kpis">'
    . kpi('layers', nf($s0['events']), 'Total events')
    . kpi('calendar', nf($s0['days']), 'Active days')
    . kpi('clock', $s0['first'] ? gmdate('d M Y', (int)$s0['first']) : '—', 'First seen')
    . kpi('pulse', $s0['last'] ? gmdate('d M H:i', (int)$s0['last']) : '—', 'Last seen')
    . kpi('tag', h($s0['ver'] ?: '—'), 'Version')
    . kpi('globe', $cc && $cc !== '??' ? flag($cc) . ' ' . h($cc) : '—', 'Country')
    . '</div>';
  $body .= '<div class="grid two"><div class="panel pad"><div class="ptitle">' . ico('list') . 'Feature usage</div><div class="psub">Everything this install has done</div>' . barlist($feat, 'event', 'c') . '</div>';
  $rf = '<div class="panel"><div class="pad" style="padding-bottom:6px"><div class="ptitle">' . ico('pulse') . 'Activity timeline</div><div class="psub">Last 100 actions</div></div><table><thead><tr><th>When (UTC)</th><th>Feature</th><th>Ver</th></tr></thead><tbody>';
  foreach ($hist as $r) $rf .= '<tr><td class="mut">' . h(gmdate('d M · H:i', (int)$r['ts'])) . '</td><td><b>' . h($r['event']) . '</b></td><td class="mut">' . h($r['version']) . '</td></tr>';
  if (!$hist) $rf .= '<tr><td colspan="3" class="empty">No activity.</td></tr>';
  $body .= $rf . '</tbody></table></div></div></div>';
  page('ApneScan Admin — Install', $body);
}

/* ---------- Range + params ---------- */
$ranges = ['1' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All time'];
$range = $_GET['r'] ?? '30'; if (!isset($ranges[$range])) $range = '30';
$days = $range === 'all' ? 3650 : (int)$range;
$since = $range === 'all' ? 0 : $now - $days * 86400;
$prevSince = $since - $days * 86400;
$q = trim($_GET['q'] ?? ''); $fv = trim($_GET['fv'] ?? ''); $fo = trim($_GET['fo'] ?? '');

/* ---------- Metrics ---------- */
$totalInstalls = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events');
$onlineNow  = (int) q1($db, 'SELECT COUNT(*) FROM live WHERE ts>=?', [$now - 300]);
$activeToday = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 86400]);
$active7  = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 7 * 86400]);
$active30 = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 30 * 86400]);
$eventsRange = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$since]);
$eventsPrev  = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=? AND ts<?', [$prevSince, $since]);
$eventsAll   = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events');
$newInstalls = (int) q1($db, 'SELECT COUNT(*) FROM (SELECT install,MIN(ts) f FROM events GROUP BY install HAVING f>=?) t', [$since]);
$newPrev     = (int) q1($db, 'SELECT COUNT(*) FROM (SELECT install,MIN(ts) f FROM events GROUP BY install HAVING f>=? AND f<?) t', [$prevSince, $since]);
$returning   = (int) q1($db, 'SELECT COUNT(*) FROM (SELECT install,COUNT(DISTINCT day) d FROM events GROUP BY install HAVING d>=2) t');
$retention   = $totalInstalls > 0 ? round($returning / $totalInstalls * 100) : 0;
$avgPerUser  = $totalInstalls > 0 ? round($eventsAll / $totalInstalls, 1) : 0;
$crashes     = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE event=? AND ts>=?', ['crash', $since]);
$newFb       = (int) q1($db, 'SELECT COUNT(*) FROM feedback WHERE seen=0');
$eDelta = $eventsPrev > 0 ? round(($eventsRange - $eventsPrev) / $eventsPrev * 100) : ($eventsRange > 0 ? 100 : 0);
$gDelta = $newPrev > 0 ? round(($newInstalls - $newPrev) / $newPrev * 100) : ($newInstalls > 0 ? 100 : 0);

// daily activity
$span = min($days, 30);
$dailyRows = qa($db, 'SELECT day, SUM(cnt) c FROM events WHERE ts>=? GROUP BY day', [$now - $span * 86400]);
$dmap = []; foreach ($dailyRows as $r) $dmap[$r['day']] = (int)$r['c'];
$daily = []; for ($i = $span - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', $now - $i * 86400); $daily[] = ['day' => $d, 'c' => (int)($dmap[$d] ?? 0)]; }
// growth
$firstRows = qa($db, 'SELECT firstday, COUNT(*) c FROM (SELECT install,MIN(day) firstday FROM events GROUP BY install) t GROUP BY firstday ORDER BY firstday');
$growth = []; $cum = 0; foreach ($firstRows as $r) { $cum += (int)$r['c']; $growth[] = ['day' => $r['firstday'], 'c' => $cum]; }
// features / versions / os / hours / country
$byEvent = qa($db, 'SELECT event, SUM(cnt) c, COUNT(DISTINCT install) u FROM events WHERE ts>=? GROUP BY event ORDER BY c DESC LIMIT 22', [$since]);
$byVersion = qa($db, 'SELECT version, COUNT(DISTINCT install) u FROM events GROUP BY version ORDER BY version DESC LIMIT 12');
$verActive = qa($db, 'SELECT version, COUNT(DISTINCT install) u FROM events WHERE ts>=? GROUP BY version ORDER BY u DESC LIMIT 10', [$now - 7 * 86400]);
$byOs = qa($db, 'SELECT os, COUNT(DISTINCT install) u FROM events GROUP BY os ORDER BY u DESC LIMIT 8');
$byCountry = qa($db, "SELECT country, COUNT(*) u FROM geo WHERE country<>'' AND country<>'??' GROUP BY country ORDER BY u DESC LIMIT 12");
$hourRows = qa($db, 'SELECT HOUR(FROM_UNIXTIME(ts)) hr, SUM(cnt) c FROM events WHERE ts>=? GROUP BY hr', [$since]);
$hours = array_fill(0, 24, 0); foreach ($hourRows as $r) $hours[(int)$r['hr']] = (int)$r['c'];
// funnel
$fInstalled = $totalInstalls;
$fScanned = (int) q1($db, "SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('scan','addPhoto','importPath','import')");
$fSaved   = (int) q1($db, "SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('savePdf','savePdfSelected','saveImages','savePdfHere','savePagesToFolder','imagesToPdf')");
$fShared  = (int) q1($db, "SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('share','shareWhatsapp','shareWindows','sharePhone')");
// cohorts (weekly by first-seen; retained = came back 7+ days later)
$cohorts = qa($db, 'SELECT MIN(mn) wkstart, COUNT(*) n, SUM(CASE WHEN mx-mn>=604800 THEN 1 ELSE 0 END) ret
                    FROM (SELECT install,MIN(ts) mn,MAX(ts) mx FROM events GROUP BY install) t
                    GROUP BY YEARWEEK(FROM_UNIXTIME(mn),3) ORDER BY wkstart DESC LIMIT 8');
// top installs
$topInstalls = qa($db, 'SELECT install, SUM(cnt) c, COUNT(DISTINCT day) days, MAX(version) ver FROM events WHERE ts>=? GROUP BY install ORDER BY c DESC LIMIT 8', [$since]);
// recent (filters)
$rw = []; $rc = '1=1';
if ($q !== '') { $rc .= ' AND event LIKE ?'; $rw[] = '%' . $q . '%'; }
if ($fv !== '') { $rc .= ' AND version=?'; $rw[] = $fv; }
if ($fo !== '') { $rc .= ' AND os=?'; $rw[] = $fo; }
$recent = qa($db, "SELECT ts,event,version,os,install FROM events WHERE $rc ORDER BY id DESC LIMIT 80", $rw);
$allVers = array_column(qa($db, 'SELECT DISTINCT version FROM events ORDER BY version DESC LIMIT 30'), 'version');
$allOs   = array_column(qa($db, 'SELECT DISTINCT os FROM events ORDER BY os LIMIT 30'), 'os');
$latestVer = ''; foreach ($byVersion as $r) { if ($r['version'] !== '') { $latestVer = $r['version']; break; } }
$onLatest = $latestVer !== '' ? (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE version=? AND ts>=?', [$latestVer, $now - 7 * 86400]) : 0;
$latestPct = $active7 > 0 ? round($onLatest / $active7 * 100) : 0;
// feedback
$fbList = qa($db, 'SELECT id,ts,version,contact,message,seen FROM feedback ORDER BY id DESC LIMIT 40');
// current settings
$curMsg = $SET['message'] ?? ''; $curType = $SET['message_type'] ?? 'info'; $curMin = $SET['min_version'] ?? '';
$curForce = ($SET['force_update'] ?? '0') === '1'; $curDl = $SET['download_url'] ?? '';
$curFlags = $SET['flags'] ?? '{}'; $curEmail = $SET['admin_email'] ?? ''; $curToken = $SET['cron_token'] ?? '';

/* ---------- Render ---------- */
$chips = '';
foreach ($ranges as $rk => $rl) { $on = $rk === $range ? ' on' : ''; $chips .= '<a class="chip' . $on . '" href="?r=' . $rk . '">' . h($rl) . '</a>'; }
$maxHour = max(1, max($hours));

$body = '<div class="bar"><div class="in"><div class="brand"><span class="logo">A</span><span>ApneScan<small>Usage Analytics &amp; Control</small></span></div>'
  . '<div class="spacer"></div>'
  . ($onlineNow > 0 ? '<span class="online"><span class="dot"></span>' . nf($onlineNow) . ' online now</span>' : '')
  . '<div class="chips">' . $chips . '</div>'
  . '<a class="ic live" id="liveBtn" title="Auto-refresh (live)" href="#">' . ico('refresh') . '</a>'
  . '<a class="ic" title="Export CSV" href="?export=1">' . ico('download') . '</a>'
  . '<a class="ic" id="themeBtn" title="Toggle theme" href="#">' . ico('moon') . '</a>'
  . '<a class="ic" title="Log out" href="?logout=1">' . ico('logout') . '</a></div></div>';

$body .= '<div class="wrap">';
if ($flash) $body .= '<div class="okmsg" style="margin-top:18px">' . h($flash) . '</div>';

// KPIs
$eb = '<span class="delta ' . ($eDelta > 0 ? 'up' : ($eDelta < 0 ? 'down' : 'flat')) . '">' . ($eDelta > 0 ? '▲ ' : ($eDelta < 0 ? '▼ ' : '')) . abs($eDelta) . '%</span>';
$gb = '<span class="delta ' . ($gDelta > 0 ? 'up' : ($gDelta < 0 ? 'down' : 'flat')) . '">' . ($gDelta > 0 ? '▲ ' : ($gDelta < 0 ? '▼ ' : '')) . abs($gDelta) . '%</span>';
$body .= '<h2 class="sec">' . ico('spark') . 'Overview · ' . h($ranges[$range]) . '</h2><div class="grid kpis">'
  . kpi('users', nf($totalInstalls), 'Total installs')
  . kpi('pulse', nf($activeToday), 'Active today')
  . kpi('calendar', nf($active7), 'Active · 7 days')
  . kpi('calendar', nf($active30), 'Active · 30 days')
  . kpi('spark', nf($newInstalls), 'New installs', $gb)
  . kpi('repeat', $retention . '%', 'Returning users')
  . kpi('layers', nf($eventsRange), 'Events', $eb)
  . kpi('trend', $avgPerUser, 'Avg events / user')
  . kpi('tag', $latestPct . '%', 'On latest version')
  . kpi('alert', nf($crashes), 'Crashes')
  . kpi('msg', nf($newFb), 'Unread feedback')
  . kpi('globe', nf(count($byCountry)), 'Countries')
  . '</div>';

// Charts
$body .= '<h2 class="sec">' . ico('chart') . 'Activity &amp; growth</h2><div class="grid two">'
  . '<div class="panel pad"><div class="ptitle">' . ico('pulse') . 'Daily activity</div><div class="psub">Events per day over the last ' . $span . ' days</div>' . areaChart($daily, 'c', '#8b5cf6', 'gEvt') . '</div>'
  . '<div class="panel pad"><div class="ptitle">' . ico('trend') . 'Install growth</div><div class="psub">Cumulative installs over time</div>' . areaChart($growth, 'c', '#6d28d9', 'gGrow') . '</div></div>';

// Funnel + cohorts
$fmax = max(1, $fInstalled);
$funnel = '<div class="funnel">';
foreach ([['Installed', $fInstalled], ['Scanned', $fScanned], ['Saved / exported', $fSaved], ['Shared', $fShared]] as $st) {
  $wd = max(6, round($st[1] / $fmax * 100)); $pct = $fInstalled > 0 ? round($st[1] / $fInstalled * 100) : 0;
  $funnel .= '<div class="fstep"><div class="bname">' . h($st[0]) . '</div><div class="fbar" style="width:' . $wd . '%">' . nf($st[1]) . '</div><div class="fpct">' . $pct . '%</div></div>';
}
$funnel .= '</div>';
$coh = '<table><thead><tr><th>Cohort week</th><th class="num">New</th><th class="num">Retained</th><th class="num">Rate</th></tr></thead><tbody>';
foreach ($cohorts as $c) { $rate = $c['n'] > 0 ? round($c['ret'] / $c['n'] * 100) : 0; $coh .= '<tr><td>' . h(gmdate('d M Y', (int)$c['wkstart'])) . '</td><td class="num">' . nf($c['n']) . '</td><td class="num">' . nf($c['ret']) . '</td><td class="num"><span class="pill">' . $rate . '%</span></td></tr>'; }
if (!$cohorts) $coh .= '<tr><td colspan="4" class="empty">No cohort data yet.</td></tr>';
$coh .= '</tbody></table>';
$body .= '<h2 class="sec">' . ico('funnel') . 'Funnel &amp; retention</h2><div class="grid two-eq">'
  . '<div class="panel pad"><div class="ptitle">' . ico('funnel') . 'Adoption funnel</div><div class="psub">Install → scan → save → share (all-time, unique users)</div>' . $funnel . '</div>'
  . '<div class="panel"><div class="pad" style="padding-bottom:6px"><div class="ptitle">' . ico('repeat') . 'Weekly cohorts</div><div class="psub">% of each week\'s new installs still active 7+ days later</div></div>' . $coh . '</div></div>';

// Features + versions
$body .= '<h2 class="sec">' . ico('tag') . 'Features &amp; versions</h2><div class="grid two">'
  . '<div class="panel pad"><div class="ptitle">' . ico('list') . 'Feature usage</div><div class="psub">What people do · % = share of all users</div>' . barlist($byEvent, 'event', 'c', $totalInstalls, 'u') . '</div>'
  . '<div class="panel pad"><div class="ptitle">' . ico('layers') . 'Version adoption (active 7d)</div><div class="psub">' . ($latestVer ? 'Latest is <b>' . h($latestVer) . '</b> · ' . $latestPct . '% adopted' : 'Users per version') . '</div>' . barlist($verActive ?: $byVersion, 'version', 'u') . '</div></div>';

// When & where
$heat = '<div class="heat">';
foreach ($hours as $hr => $c) { $op = $c > 0 ? max(0.14, $c / $maxHour) : 0.05; $heat .= '<div class="hc" title="' . $hr . ':00 UTC — ' . nf($c) . ' events" style="background:color-mix(in srgb,var(--brand) ' . round($op * 100) . '%,transparent)"></div>'; }
$heat .= '</div><div class="hlabels"><span>0</span><span style="grid-column:7">6</span><span style="grid-column:13">12</span><span style="grid-column:19">18</span><span style="grid-column:24">23</span></div>';
$countryRows = [];
foreach ($byCountry as $r) $countryRows[] = ['name' => flag($r['country']) . ' ' . $r['country'], 'u' => $r['u']];
$body .= '<h2 class="sec">' . ico('globe') . 'When &amp; where</h2><div class="grid two"><div class="panel pad"><div class="ptitle">' . ico('clock') . 'Active hours (UTC)</div><div class="psub">Events by hour of day</div>' . $heat
  . '<div style="margin-top:20px"><div class="ptitle">' . ico('monitor') . 'Operating system</div><div class="psub">Windows build in use</div>' . barlist($byOs, 'os', 'u') . '</div></div>'
  . '<div class="panel pad"><div class="ptitle">' . ico('globe') . 'Countries</div><div class="psub">Where installs are located (coarse, from IP)</div>'
  . ($countryRows ? barlist($countryRows, 'name', 'u') : '<div class="empty">Country data appears as users on the new build come online.</div>') . '</div></div>';

// Top installs + recent feed with filters
$ti = '<div class="panel"><div class="pad" style="padding-bottom:6px"><div class="ptitle">' . ico('crown') . 'Most active installs</div><div class="psub">Click an ID to see everything that install did</div></div><table><thead><tr><th>Install</th><th class="num">Days</th><th>Version</th><th class="num">Events</th></tr></thead><tbody>';
foreach ($topInstalls as $r) $ti .= '<tr><td><a class="mono" href="?install=' . h($r['install']) . '">' . h(substr($r['install'], 0, 10)) . '</a></td><td class="num">' . (int)$r['days'] . '</td><td><span class="pill">' . h($r['ver'] ?: '—') . '</span></td><td class="num">' . nf($r['c']) . '</td></tr>';
if (!$topInstalls) $ti .= '<tr><td colspan="4" class="empty">No activity in this range yet.</td></tr>';
$ti .= '</tbody></table></div>';

$vopts = '<option value="">All versions</option>'; foreach ($allVers as $v) { if ($v === '') continue; $vopts .= '<option value="' . h($v) . '"' . ($v === $fv ? ' selected' : '') . '>' . h($v) . '</option>'; }
$oopts = '<option value="">All OS</option>'; foreach ($allOs as $o) { if ($o === '') continue; $oopts .= '<option value="' . h($o) . '"' . ($o === $fo ? ' selected' : '') . '>' . h($o) . '</option>'; }
$rf = '<div class="panel"><div class="pad" style="padding-bottom:8px"><div class="ptitle">' . ico('pulse') . 'Recent events</div>'
  . '<form class="search" method="get"><input type="hidden" name="r" value="' . h($range) . '">'
  . '<input name="q" value="' . h($q) . '" placeholder="Search feature…"><select class="f" name="fv" style="width:auto;flex:0">' . $vopts . '</select>'
  . '<select class="f" name="fo" style="width:auto;flex:0">' . $oopts . '</select><button class="sbtn">' . ico('search') . '</button></form></div>'
  . '<table><thead><tr><th>When (UTC)</th><th>Feature</th><th>Ver</th><th>Install</th></tr></thead><tbody>';
foreach ($recent as $r) $rf .= '<tr><td class="mut">' . h(gmdate('d M · H:i', (int)$r['ts'])) . '</td><td><b>' . h($r['event']) . '</b></td><td class="mut">' . h($r['version']) . '</td><td><a class="mono" href="?install=' . h($r['install']) . '">' . h(substr($r['install'], 0, 8)) . '</a></td></tr>';
if (!$recent) $rf .= '<tr><td colspan="4" class="empty">No matching events.</td></tr>';
$rf .= '</tbody></table></div>';
$body .= '<h2 class="sec">' . ico('crown') . 'Top users &amp; live feed</h2><div class="grid two">' . $ti . $rf . '</div>';

// Feedback inbox
$fb = '<div class="panel"><div class="pad" style="padding-bottom:6px"><div class="ptitle">' . ico('msg') . 'User feedback</div><div class="psub">Messages sent from inside the app</div></div>';
if ($fbList) {
  foreach ($fbList as $f) {
    $fb .= '<div class="fbitem"><div class="fbmeta">' . ($f['seen'] ? '' : '<span class="newdot"></span> ') . h(gmdate('d M Y · H:i', (int)$f['ts'])) . ' · v' . h($f['version']) . ($f['contact'] ? ' · ' . h($f['contact']) : '') . '<span class="spacer" style="flex:1"></span>'
      . '<form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="id" value="' . (int)$f['id'] . '">'
      . ($f['seen'] ? '' : '<button class="xbtn" name="action" value="fbseen" style="margin-right:6px">Mark read</button>')
      . '<button class="xbtn" name="action" value="fbdel" onclick="return confirm(\'Delete this feedback?\')">Delete</button></form></div>'
      . '<div class="fbmsg">' . nl2br(h($f['message'])) . '</div></div>';
  }
} else { $fb .= '<div class="empty">No feedback yet. It arrives when users tap “Send feedback” in the app.</div>'; }
$fb .= '</div>';
$body .= '<h2 class="sec">' . ico('msg') . 'Feedback inbox</h2>' . $fb;

// Controls
$body .= '<h2 class="sec">' . ico('settings') . 'App controls</h2><div class="grid two">';
// broadcast/flags/update
$body .= '<div class="panel pad"><form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="savecfg">'
  . '<div class="ptitle">' . ico('msg') . 'Broadcast &amp; remote control</div><div class="psub">Pushed to every app on its next launch</div>'
  . '<label>Broadcast banner message (blank = none)</label><textarea class="f" name="message" placeholder="e.g. New version available with faster scanning!">' . h($curMsg) . '</textarea>'
  . '<label>Banner style</label><select class="f" name="message_type">'
  . '<option value="info"' . ($curType === 'info' ? ' selected' : '') . '>Info (purple)</option>'
  . '<option value="success"' . ($curType === 'success' ? ' selected' : '') . '>Success (green)</option>'
  . '<option value="warning"' . ($curType === 'warning' ? ' selected' : '') . '>Warning (amber)</option></select>'
  . '<label>Minimum required version (older versions get an update prompt)</label><input class="f" name="min_version" value="' . h($curMin) . '" placeholder="e.g. 1.0.72">'
  . '<div class="chkrow"><input type="checkbox" name="force_update" id="fu"' . ($curForce ? ' checked' : '') . '><label for="fu" style="margin:0">Force update — block older versions until updated</label></div>'
  . '<label>Download URL (for the update prompt)</label><input class="f" name="download_url" value="' . h($curDl) . '" placeholder="https://…/ApneScan-Setup.exe">'
  . '<label>Feature flags (JSON — remotely turn features on/off)</label><textarea class="f" name="flags" placeholder=\'{"betaOcr": true}\'>' . h($curFlags) . '</textarea>'
  . '<label>Daily-summary email (leave blank to disable)</label><input class="f" name="admin_email" value="' . h($curEmail) . '" placeholder="you@example.com">'
  . '<label>Cron token (for the daily email job)</label><input class="f" name="cron_token" value="' . h($curToken) . '" placeholder="a random word — set once">'
  . '<button class="savebtn">Save settings</button></form></div>';
// password change
$body .= '<div class="panel pad"><form method="post"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="action" value="passwd">'
  . '<div class="ptitle">' . ico('key') . 'Change admin password</div><div class="psub">Password for this dashboard</div>'
  . '<label>Current password</label><input class="f" type="password" name="old">'
  . '<label>New password</label><input class="f" type="password" name="n1" placeholder="6+ characters">'
  . '<label>Confirm new password</label><input class="f" type="password" name="n2">'
  . '<button class="savebtn">Change password</button></form>'
  . '<div class="psub" style="margin-top:22px"><b>Daily email setup:</b> in Hostinger → Advanced → Cron Jobs, add a daily job:<br><span class="mono">wget -q -O /dev/null "https://apnescan.subhashkaler.com/api/summary.php?token=YOUR_TOKEN"</span></div></div>';
$body .= '</div>';

$body .= '<p class="foot">🔒 Anonymous usage only — no document content, filenames, or personal data is ever collected. IPs are stored only as a one-way hash plus a coarse country code.</p></div>';

$js = '<script>(function(){var root=document.documentElement;'
  . 'try{var t=localStorage.getItem("as_theme");if(t)root.setAttribute("data-theme",t);}catch(e){}'
  . 'var tb=document.getElementById("themeBtn");if(tb)tb.onclick=function(e){e.preventDefault();var cur=root.getAttribute("data-theme")||(matchMedia("(prefers-color-scheme:dark)").matches?"dark":"light");var nx=cur==="dark"?"light":"dark";root.setAttribute("data-theme",nx);try{localStorage.setItem("as_theme",nx);}catch(e){}};'
  . 'var lb=document.getElementById("liveBtn"),timer=null;function setLive(on){try{localStorage.setItem("as_live",on?"1":"0");}catch(e){}if(lb)lb.classList.toggle("on",on);if(on){timer=setTimeout(function(){location.reload();},20000);}else if(timer){clearTimeout(timer);}}'
  . 'var live=false;try{live=localStorage.getItem("as_live")==="1";}catch(e){}setLive(live);if(lb)lb.onclick=function(e){e.preventDefault();live=!live;setLive(live);};})();</script>';

page('ApneScan Admin — Dashboard', $body, $js);
