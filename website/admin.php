<?php
// ApneScan — admin analytics dashboard (anonymous usage stats).
// First run: a setup wizard writes api/config.php (MySQL + admin password).
// After that: log in with the admin password to view the dashboard.
session_start();
// Config lives inside api/ (protected by api/.htaccess) and is shared with
// api/track.php so both the dashboard and the ingest use the same DB details.
$CFG = __DIR__ . '/api/config.php';
@is_dir(__DIR__ . '/api') || @mkdir(__DIR__ . '/api', 0755, true);
$DEFAULTS = ['host' => 'localhost', 'name' => 'u246829578_apnescan', 'user' => 'u246829578_apnescan'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n) { return number_format((float)$n); }

/* ---------- Shared page shell + design system ---------- */
function page($title, $body, $extraHead = '') {
  $css = <<<'CSS'
:root{
  --bg:#f3f0fb; --bg2:#ece6f8; --panel:#ffffff; --panel2:#faf8ff;
  --ink:#191426; --muted:#6d6785; --faint:#9a94ad; --line:#e9e4f4;
  --brand:#6d28d9; --brand2:#9333ea; --accent:#8b5cf6;
  --good:#16a34a; --warn:#d97706; --bad:#dc2626;
  --shadow:0 1px 2px rgba(24,16,54,.04),0 8px 24px rgba(24,16,54,.06);
  --shadow-lg:0 8px 40px rgba(76,29,149,.10);
}
@media(prefers-color-scheme:dark){:root{
  --bg:#0c0a13; --bg2:#0f0c18; --panel:#161120; --panel2:#1b1526; --ink:#f1eef8;
  --muted:#a49dba; --faint:#726b87; --line:#251f34;
  --brand:#a78bfa; --brand2:#c084fc; --accent:#8b5cf6;
  --shadow:0 1px 2px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.35);
  --shadow-lg:0 8px 40px rgba(0,0,0,.5);
}}
:root[data-theme=light]{color-scheme:light}
:root[data-theme=dark]{
  --bg:#0c0a13; --bg2:#0f0c18; --panel:#161120; --panel2:#1b1526; --ink:#f1eef8;
  --muted:#a49dba; --faint:#726b87; --line:#251f34;
  --brand:#a78bfa; --brand2:#c084fc; --accent:#8b5cf6; color-scheme:dark;
}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;color:var(--ink);font-family:Inter,system-ui,'Segoe UI',Roboto,sans-serif;
  background:radial-gradient(1200px 600px at 80% -10%,color-mix(in srgb,var(--brand) 12%,transparent),transparent 60%),
             linear-gradient(180deg,var(--bg),var(--bg2));
  min-height:100vh;font-feature-settings:'cv02','cv03','cv04';-webkit-font-smoothing:antialiased}
.wrap{max-width:1180px;margin:0 auto;padding:0 20px 60px}
h1,h2,h3{font-family:'Bricolage Grotesque',Inter,system-ui,sans-serif;letter-spacing:-.01em}
a{color:var(--brand)}
.mut{color:var(--muted);font-size:12.5px}
.faint{color:var(--faint)}

/* top bar */
.bar{position:sticky;top:0;z-index:20;backdrop-filter:saturate(1.4) blur(14px);
  background:color-mix(in srgb,var(--bg) 82%,transparent);border-bottom:1px solid var(--line)}
.bar .in{max-width:1180px;margin:0 auto;padding:13px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:11px;font-weight:800;font-size:16px;
  font-family:'Bricolage Grotesque',sans-serif}
.logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));
  display:grid;place-items:center;color:#fff;font-weight:800;box-shadow:0 4px 14px color-mix(in srgb,var(--brand) 45%,transparent)}
.brand small{display:block;font-size:11px;font-weight:600;color:var(--muted);font-family:Inter,sans-serif;margin-top:1px}
.spacer{flex:1}
.chips{display:flex;gap:4px;background:var(--panel);border:1px solid var(--line);border-radius:11px;padding:4px;box-shadow:var(--shadow)}
.chip{padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted);text-decoration:none;white-space:nowrap}
.chip:hover{color:var(--ink);background:var(--panel2)}
.chip.on{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand2));box-shadow:0 3px 10px color-mix(in srgb,var(--brand) 40%,transparent)}
.ic{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;
  border:1px solid var(--line);background:var(--panel);color:var(--muted);cursor:pointer;text-decoration:none;box-shadow:var(--shadow)}
.ic:hover{color:var(--brand);border-color:color-mix(in srgb,var(--brand) 40%,var(--line))}
.ic.live.on{color:#fff;background:linear-gradient(135deg,var(--good),#22c55e);border-color:transparent}
.ic svg{width:18px;height:18px}

/* layout */
h2.sec{font-size:12px;margin:30px 0 12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;font-weight:700;
  font-family:Inter,sans-serif;display:flex;align-items:center;gap:8px}
h2.sec svg{width:15px;height:15px;opacity:.8}
.grid{display:grid;gap:16px}
.kpis{grid-template-columns:repeat(auto-fill,minmax(178px,1fr))}
.two{grid-template-columns:1.4fr 1fr}
.two-eq{grid-template-columns:1fr 1fr}
@media(max-width:820px){.two,.two-eq{grid-template-columns:1fr}}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);overflow:hidden}
.pad{padding:18px 20px}
.ptitle{font-size:13.5px;font-weight:700;margin:0 0 2px;display:flex;align-items:center;gap:8px}
.psub{font-size:12px;color:var(--faint);margin:0 0 14px}

/* KPI card */
.kpi{position:relative;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:16px 17px;box-shadow:var(--shadow);overflow:hidden}
.kpi::after{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand2))}
.kpi .row{display:flex;align-items:center;justify-content:space-between}
.kpi .bo{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;
  background:color-mix(in srgb,var(--brand) 12%,transparent);color:var(--brand)}
.kpi .bo svg{width:17px;height:17px}
.kpi .n{font-size:30px;font-weight:800;line-height:1.05;margin-top:12px;font-variant-numeric:tabular-nums;font-family:'Bricolage Grotesque',sans-serif}
.kpi .l{color:var(--muted);font-size:12.5px;margin-top:3px;font-weight:500}
.delta{font-size:11.5px;font-weight:700;padding:2px 7px;border-radius:20px}
.delta.up{color:var(--good);background:color-mix(in srgb,var(--good) 14%,transparent)}
.delta.flat{color:var(--faint);background:color-mix(in srgb,var(--faint) 14%,transparent)}

/* bar list */
.blist{display:flex;flex-direction:column;gap:11px}
.brow{display:grid;grid-template-columns:130px 1fr auto;align-items:center;gap:12px}
.bname{font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btrack{height:9px;border-radius:20px;background:color-mix(in srgb,var(--brand) 9%,transparent);overflow:hidden}
.bfill{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--brand),var(--accent))}
.bval{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--muted);min-width:34px;text-align:right}
.tag{font-size:10.5px;font-weight:700;color:var(--brand);background:color-mix(in srgb,var(--brand) 12%,transparent);padding:1px 6px;border-radius:6px;margin-left:6px}

/* table */
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--line)}
th{color:var(--faint);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover{background:var(--panel2)}
td.num{text-align:right;font-variant-numeric:tabular-nums}
.pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;
  background:color-mix(in srgb,var(--accent) 14%,transparent);color:var(--brand)}
.mono{font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:11.5px;color:var(--muted)}

/* heatmap */
.heat{display:grid;grid-template-columns:repeat(24,1fr);gap:4px}
.hc{aspect-ratio:1;border-radius:5px;background:color-mix(in srgb,var(--brand) 8%,transparent)}
.hlabels{display:grid;grid-template-columns:repeat(24,1fr);gap:4px;margin-top:6px;font-size:9px;color:var(--faint);text-align:center}

/* search */
.search{display:flex;gap:8px;margin:0 0 12px}
.search input{flex:1;padding:9px 13px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:13px;background:var(--panel2);color:var(--ink)}
.search input:focus{outline:none;border-color:var(--brand)}
.sbtn{padding:9px 14px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}

/* login/setup */
form.box{max-width:430px;margin:9vh auto;background:var(--panel);border:1px solid var(--line);border-radius:20px;padding:30px;box-shadow:var(--shadow-lg)}
form.box .brand{font-size:18px;margin-bottom:6px}
label{display:block;font-size:12.5px;font-weight:600;margin:15px 0 6px}
input.f{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font:inherit;background:var(--panel2);color:var(--ink)}
input.f:focus{outline:none;border-color:var(--brand)}
.btn{margin-top:20px;width:100%;padding:12px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:15px;cursor:pointer;box-shadow:0 6px 18px color-mix(in srgb,var(--brand) 40%,transparent)}
.msg{background:color-mix(in srgb,var(--bad) 12%,transparent);color:var(--bad);padding:11px 13px;border-radius:10px;font-size:13px;margin-top:14px}
.foot{text-align:center;margin-top:34px;color:var(--faint);font-size:12px}
.empty{padding:26px;text-align:center;color:var(--faint);font-size:13px}
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
        } else {
          $_SESSION['ok'] = true;
          header('Location: admin.php'); exit;
        }
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
$db->exec('CREATE TABLE IF NOT EXISTS events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    install VARCHAR(40), event VARCHAR(40), version VARCHAR(20), os VARCHAR(40),
    cnt INT, iphash VARCHAR(16), ts INT, day CHAR(10),
    INDEX idx_ts (ts), INDEX idx_install (install)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

function q1($db, $sql, $a = []) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function qa($db, $sql, $a = []) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }

/* ---------- CSV export ---------- */
if (isset($_GET['export'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="apnescan-events.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['datetime_utc', 'event', 'version', 'os', 'count', 'install']);
  foreach ($db->query('SELECT ts,event,version,os,cnt,install FROM events ORDER BY id DESC LIMIT 100000') as $r) {
    fputcsv($out, [gmdate('Y-m-d H:i:s', (int)$r['ts']), $r['event'], $r['version'], $r['os'], $r['cnt'], substr($r['install'], 0, 10)]);
  }
  fclose($out); exit;
}

/* ---------- Range + params ---------- */
$now = time();
$ranges = ['1' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days', 'all' => 'All time'];
$range = $_GET['r'] ?? '30';
if (!isset($ranges[$range])) $range = '30';
$days = $range === 'all' ? 3650 : (int)$range;
$since = $range === 'all' ? 0 : $now - $days * 86400;
$prevSince = $since - $days * 86400; // for delta comparison
$q = trim($_GET['q'] ?? '');

/* ---------- Metrics ---------- */
$totalInstalls = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events');
$activeToday = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 86400]);
$active7  = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 7 * 86400]);
$active30 = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 30 * 86400]);
$activeRange = (int) q1($db, 'SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$since]);
$eventsRange = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$since]);
$eventsPrev  = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=? AND ts<?', [$prevSince, $since]);
$eventsAll   = (int) q1($db, 'SELECT COALESCE(SUM(cnt),0) FROM events');
$newInstalls = (int) q1($db, 'SELECT COUNT(*) FROM (SELECT install,MIN(ts) f FROM events GROUP BY install HAVING f>=?) t', [$since]);
$returning   = (int) q1($db, 'SELECT COUNT(*) FROM (SELECT install,COUNT(DISTINCT day) d FROM events GROUP BY install HAVING d>=2) t');
$retention   = $totalInstalls > 0 ? round($returning / $totalInstalls * 100) : 0;
$avgPerUser  = $totalInstalls > 0 ? round($eventsAll / $totalInstalls, 1) : 0;

// event delta %
$delta = $eventsPrev > 0 ? round(($eventsRange - $eventsPrev) / $eventsPrev * 100) : ($eventsRange > 0 ? 100 : 0);

// daily series (active users + events) over the window (cap 30 buckets for readability)
$span = min($days, 30);
$dailyRows = qa($db, 'SELECT day, COUNT(DISTINCT install) u, SUM(cnt) c FROM events WHERE ts>=? GROUP BY day ORDER BY day', [$now - $span * 86400]);
$dmap = []; foreach ($dailyRows as $r) $dmap[$r['day']] = $r;
$daily = [];
for ($i = $span - 1; $i >= 0; $i--) {
  $d = gmdate('Y-m-d', $now - $i * 86400);
  $daily[] = ['day' => $d, 'u' => (int)($dmap[$d]['u'] ?? 0), 'c' => (int)($dmap[$d]['c'] ?? 0)];
}

// cumulative installs growth (all-time, by first-seen day)
$firstRows = qa($db, 'SELECT firstday, COUNT(*) c FROM (SELECT install,MIN(day) firstday FROM events GROUP BY install) t GROUP BY firstday ORDER BY firstday');
$growth = []; $cum = 0;
foreach ($firstRows as $r) { $cum += (int)$r['c']; $growth[] = ['day' => $r['firstday'], 'c' => $cum]; }

// feature usage + adoption (window)
$byEvent = qa($db, 'SELECT event, SUM(cnt) c, COUNT(DISTINCT install) u FROM events WHERE ts>=? GROUP BY event ORDER BY c DESC LIMIT 22', [$since]);
$byVersion = qa($db, 'SELECT version, COUNT(DISTINCT install) u, SUM(cnt) c FROM events GROUP BY version ORDER BY version DESC LIMIT 12');
$byOs = qa($db, 'SELECT os, COUNT(DISTINCT install) u FROM events GROUP BY os ORDER BY u DESC LIMIT 10');

// hourly heatmap (window, UTC hour)
$hourRows = qa($db, 'SELECT HOUR(FROM_UNIXTIME(ts)) hr, SUM(cnt) c FROM events WHERE ts>=? GROUP BY hr', [$since]);
$hours = array_fill(0, 24, 0); foreach ($hourRows as $r) $hours[(int)$r['hr']] = (int)$r['c'];

// top installs (window)
$topInstalls = qa($db, 'SELECT install, SUM(cnt) c, COUNT(DISTINCT day) days, MAX(ts) last, MAX(version) ver
                        FROM events WHERE ts>=? GROUP BY install ORDER BY c DESC LIMIT 8', [$since]);

// recent events (optional search)
if ($q !== '') {
  $recent = qa($db, 'SELECT ts,event,version,os,install,cnt FROM events WHERE event LIKE ? ORDER BY id DESC LIMIT 80', ['%' . $q . '%']);
} else {
  $recent = qa($db, 'SELECT ts,event,version,os,install,cnt FROM events ORDER BY id DESC LIMIT 80');
}
$latestVer = '';
foreach ($byVersion as $r) { if ($r['version'] !== '') { $latestVer = $r['version']; break; } }

/* ---------- Rendering helpers ---------- */
function ico($name) {
  $p = [
    'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'pulse'   => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
    'spark'   => '<path d="M5 3v4M3 5h4M6 17v4m-2-2h4"/><path d="M13 3l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5z"/>',
    'calendar'=> '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'repeat'  => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    'layers'  => '<path d="M12 2l10 6-10 6L2 8z"/><path d="M2 12l10 6 10-6M2 16l10 6 10-6"/>',
    'chart'   => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'trend'   => '<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>',
    'grid'    => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    'tag'     => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1"/>',
    'monitor' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
    'clock'   => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'list'    => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'crown'   => '<path d="M2 20h20M4 6l4 6 4-8 4 8 4-6v10H4z"/>',
    'download'=> '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
    'refresh' => '<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.65 4.36A9 9 0 0 0 20.5 15"/>',
    'moon'    => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
    'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
    'search'  => '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>',
  ];
  $d = $p[$name] ?? '';
  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg>';
}

function kpi($icon, $n, $label, $badge = '') {
  return '<div class="kpi"><div class="row"><span class="bo">' . ico($icon) . '</span>' . $badge . '</div>'
       . '<div class="n">' . $n . '</div><div class="l">' . h($label) . '</div></div>';
}

// SVG area chart. $series: list of ['day'=>,'v'=>]; picks key $k.
function areaChart($series, $k, $stroke, $fillId) {
  $n = count($series);
  if ($n < 2) return '<div class="empty">Not enough data yet — chart appears once there are a couple of days of activity.</div>';
  $w = 960; $hh = 210; $pad = 10; $base = $hh - 22;
  $max = 1; foreach ($series as $s) $max = max($max, (int)$s[$k]);
  $stepX = ($w - 2 * $pad) / ($n - 1);
  $pts = [];
  foreach ($series as $i => $s) {
    $x = $pad + $i * $stepX;
    $y = $base - ((int)$s[$k] / $max) * ($base - $pad);
    $pts[] = [round($x, 1), round($y, 1)];
  }
  $line = 'M ' . $pts[0][0] . ' ' . $pts[0][1];
  for ($i = 1; $i < $n; $i++) $line .= ' L ' . $pts[$i][0] . ' ' . $pts[$i][1];
  $area = $line . ' L ' . $pts[$n - 1][0] . ' ' . $base . ' L ' . $pts[0][0] . ' ' . $base . ' Z';
  $last = $pts[$n - 1];
  // gridlines
  $grid = '';
  for ($g = 1; $g <= 3; $g++) { $gy = round($pad + ($base - $pad) * $g / 4, 1); $grid .= '<line x1="' . $pad . '" y1="' . $gy . '" x2="' . ($w - $pad) . '" y2="' . $gy . '" stroke="currentColor" stroke-width="1" opacity=".08"/>'; }
  return '<svg viewBox="0 0 ' . $w . ' ' . $hh . '" preserveAspectRatio="none" style="width:100%;height:200px;color:var(--ink)">'
       . '<defs><linearGradient id="' . $fillId . '" x1="0" y1="0" x2="0" y2="1">'
       . '<stop offset="0" stop-color="' . $stroke . '" stop-opacity=".28"/><stop offset="1" stop-color="' . $stroke . '" stop-opacity="0"/></linearGradient></defs>'
       . $grid
       . '<path d="' . $area . '" fill="url(#' . $fillId . ')"/>'
       . '<path d="' . $line . '" fill="none" stroke="' . $stroke . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>'
       . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" fill="' . $stroke . '"/>'
       . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="8" fill="' . $stroke . '" opacity=".22"/>'
       . '</svg>';
}

function barlist($rows, $namek, $valk, $total = 0, $adoptk = null) {
  if (!$rows) return '<div class="empty">No data in this range yet.</div>';
  $max = 0; foreach ($rows as $r) $max = max($max, (int)$r[$valk]);
  $out = '<div class="blist">';
  foreach ($rows as $r) {
    $v = (int)$r[$valk]; $wd = $max > 0 ? max(3, round($v / $max * 100)) : 0;
    $name = $r[$namek] === '' ? '(unknown)' : $r[$namek];
    $ad = ($adoptk !== null && $total > 0) ? ' <span class="tag">' . round((int)$r[$adoptk] / $total * 100) . '%</span>' : '';
    $out .= '<div class="brow"><div class="bname" title="' . h($name) . '">' . h($name) . $ad . '</div>'
          . '<div class="btrack"><div class="bfill" style="width:' . $wd . '%"></div></div>'
          . '<div class="bval">' . nf($v) . '</div></div>';
  }
  return $out . '</div>';
}

/* ---------- Build page ---------- */
$chips = '';
foreach ($ranges as $rk => $rlabel) {
  $on = $rk === $range ? ' on' : '';
  $chips .= '<a class="chip' . $on . '" href="?r=' . $rk . ($q !== '' ? '&q=' . urlencode($q) : '') . '">' . h($rlabel) . '</a>';
}

$maxHour = max(1, max($hours));

$body = '<div class="bar"><div class="in">'
  . '<div class="brand"><span class="logo">A</span><span>ApneScan<small>Usage Analytics</small></span></div>'
  . '<div class="spacer"></div>'
  . '<div class="chips">' . $chips . '</div>'
  . '<a class="ic live" id="liveBtn" title="Auto-refresh (live)" href="#">' . ico('refresh') . '</a>'
  . '<a class="ic" title="Export events as CSV" href="?export=1">' . ico('download') . '</a>'
  . '<a class="ic" id="themeBtn" title="Toggle theme" href="#">' . ico('moon') . '</a>'
  . '<a class="ic" title="Log out" href="?logout=1">' . ico('logout') . '</a>'
  . '</div></div>';

$body .= '<div class="wrap">';

// KPI row
$deltaBadge = '<span class="delta ' . ($delta > 0 ? 'up' : 'flat') . '">' . ($delta > 0 ? '▲ ' : '') . $delta . '%</span>';
$body .= '<h2 class="sec">' . ico('spark') . 'Overview · ' . h($ranges[$range]) . '</h2>';
$body .= '<div class="grid kpis">'
  . kpi('users', nf($totalInstalls), 'Total installs')
  . kpi('pulse', nf($activeToday), 'Active today')
  . kpi('calendar', nf($active7), 'Active · 7 days')
  . kpi('calendar', nf($active30), 'Active · 30 days')
  . kpi('spark', nf($newInstalls), 'New installs (' . h($ranges[$range]) . ')')
  . kpi('repeat', $retention . '%', 'Returning users')
  . kpi('layers', nf($eventsRange), 'Events (' . h($ranges[$range]) . ')', $deltaBadge)
  . kpi('trend', $avgPerUser, 'Avg events / user')
  . '</div>';

// Activity chart + growth
$body .= '<h2 class="sec">' . ico('chart') . 'Activity</h2>';
$body .= '<div class="grid two">'
  . '<div class="panel pad"><div class="ptitle">' . ico('pulse') . 'Daily activity</div><div class="psub">Events per day over the last ' . $span . ' days</div>'
  . areaChart($daily, 'c', '#8b5cf6', 'gEvt') . '</div>'
  . '<div class="panel pad"><div class="ptitle">' . ico('trend') . 'Install growth</div><div class="psub">Cumulative installs over time</div>'
  . areaChart($growth, 'c', '#6d28d9', 'gGrow') . '</div>'
  . '</div>';

// Feature usage + versions
$body .= '<h2 class="sec">' . ico('tag') . 'Features &amp; adoption</h2>';
$body .= '<div class="grid two">'
  . '<div class="panel pad"><div class="ptitle">' . ico('list') . 'Feature usage</div><div class="psub">What people do in the app · % = share of users who used it</div>'
  . barlist($byEvent, 'event', 'c', $totalInstalls, 'u') . '</div>'
  . '<div class="panel pad"><div class="ptitle">' . ico('layers') . 'App versions</div><div class="psub">Users on each version'
  . ($latestVer ? ' · latest is <b>' . h($latestVer) . '</b>' : '') . '</div>'
  . barlist($byVersion, 'version', 'u') . '</div>'
  . '</div>';

// Heatmap + OS
$heat = '<div class="heat">';
foreach ($hours as $hr => $c) {
  $op = $c > 0 ? max(0.14, $c / $maxHour) : 0.05;
  $heat .= '<div class="hc" title="' . $hr . ':00 UTC — ' . nf($c) . ' events" style="background:color-mix(in srgb,var(--brand) ' . round($op * 100) . '%,transparent)"></div>';
}
$heat .= '</div><div class="hlabels"><span>0</span><span style="grid-column:7">6</span><span style="grid-column:13">12</span><span style="grid-column:19">18</span><span style="grid-column:24">23</span></div>';
$body .= '<h2 class="sec">' . ico('clock') . 'When &amp; where</h2>';
$body .= '<div class="grid two">'
  . '<div class="panel pad"><div class="ptitle">' . ico('clock') . 'Active hours</div><div class="psub">Events by hour of day (UTC)</div>' . $heat . '</div>'
  . '<div class="panel pad"><div class="ptitle">' . ico('monitor') . 'Operating system</div><div class="psub">Windows build in use</div>'
  . barlist($byOs, 'os', 'u') . '</div>'
  . '</div>';

// Top installs
$body .= '<h2 class="sec">' . ico('crown') . 'Top users &amp; live feed</h2>';
$body .= '<div class="grid two">';
$ti = '<div class="panel"><div class="pad" style="padding-bottom:6px"><div class="ptitle">' . ico('crown') . 'Most active installs</div><div class="psub">Anonymous device IDs, ranked by activity in this range</div></div>'
  . '<table><thead><tr><th>Install</th><th class="num">Days</th><th>Version</th><th class="num">Events</th></tr></thead><tbody>';
if ($topInstalls) {
  foreach ($topInstalls as $r) {
    $ti .= '<tr><td class="mono">' . h(substr($r['install'], 0, 10)) . '</td>'
         . '<td class="num">' . (int)$r['days'] . '</td>'
         . '<td><span class="pill">' . h($r['ver'] ?: '—') . '</span></td>'
         . '<td class="num">' . nf($r['c']) . '</td></tr>';
  }
} else { $ti .= '<tr><td colspan="4" class="empty">No activity in this range yet.</td></tr>'; }
$ti .= '</tbody></table></div>';

// Recent feed with search
$rf = '<div class="panel"><div class="pad" style="padding-bottom:8px"><div class="ptitle">' . ico('pulse') . 'Recent events</div>'
  . '<form class="search" method="get"><input type="hidden" name="r" value="' . h($range) . '">'
  . '<input name="q" value="' . h($q) . '" placeholder="Search a feature… e.g. scan, sharePdf, delete">'
  . '<button class="sbtn">' . ico('search') . '</button></form></div>'
  . '<table><thead><tr><th>When (UTC)</th><th>Feature</th><th>Ver</th><th>Install</th></tr></thead><tbody>';
if ($recent) {
  foreach ($recent as $r) {
    $rf .= '<tr><td class="mut">' . h(gmdate('d M · H:i', (int)$r['ts'])) . '</td>'
         . '<td><b>' . h($r['event']) . '</b></td>'
         . '<td class="mut">' . h($r['version']) . '</td>'
         . '<td class="mono">' . h(substr($r['install'], 0, 8)) . '</td></tr>';
  }
} else { $rf .= '<tr><td colspan="4" class="empty">No events' . ($q !== '' ? ' matching “' . h($q) . '”' : ' yet') . '.</td></tr>'; }
$rf .= '</tbody></table></div>';

$body .= $ti . $rf . '</div>';

$body .= '<p class="foot">🔒 Anonymous usage only — no document content, filenames, or personal data is ever collected. '
       . 'IP addresses are stored only as a one-way hash for rough unique counting.</p>';
$body .= '</div>';

$js = '<script>'
  . '(function(){'
  . 'var root=document.documentElement;'
  . 'try{var t=localStorage.getItem("as_theme");if(t)root.setAttribute("data-theme",t);}catch(e){}'
  . 'var tb=document.getElementById("themeBtn");if(tb)tb.onclick=function(e){e.preventDefault();'
  . 'var cur=root.getAttribute("data-theme")||(matchMedia("(prefers-color-scheme:dark)").matches?"dark":"light");'
  . 'var nx=cur==="dark"?"light":"dark";root.setAttribute("data-theme",nx);try{localStorage.setItem("as_theme",nx);}catch(e){}};'
  . 'var lb=document.getElementById("liveBtn");var timer=null;'
  . 'function setLive(on){try{localStorage.setItem("as_live",on?"1":"0");}catch(e){}'
  . 'if(lb)lb.classList.toggle("on",on);if(on){timer=setTimeout(function(){location.reload();},20000);}else if(timer){clearTimeout(timer);}}'
  . 'var live=false;try{live=localStorage.getItem("as_live")==="1";}catch(e){}setLive(live);'
  . 'if(lb)lb.onclick=function(e){e.preventDefault();live=!live;setLive(live);};'
  . '})();</script>';

page('ApneScan Admin — Dashboard', $body, $js);
