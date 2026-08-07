<?php
// ApneScan — daily email summary. Meant to be called once a day by a Hostinger
// cron job:  php /home/USER/domains/.../public_html/apnescan/api/summary.php TOKEN
// or via URL:  https://apnescan.subhashkaler.com/api/summary.php?token=TOKEN
// The token and destination email are set from the admin dashboard.
header('Content-Type: text/plain; charset=utf-8');

$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { http_response_code(503); echo 'not configured'; exit; }
require $cfg;

$token = $_GET['token'] ?? ($argv[1] ?? '');

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $st = $db->query('SELECT k,v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);

    $expected = (string)($st['cron_token'] ?? '');
    $email    = (string)($st['admin_email'] ?? '');
    if ($expected === '' || !hash_equals($expected, (string)$token)) { http_response_code(403); echo 'bad token'; exit; }
    if ($email === '') { echo 'no admin email set'; exit; }

    $now = time();
    $q1 = function ($sql, $a = []) use ($db) { $s = $db->prepare($sql); $s->execute($a); return $s->fetchColumn(); };

    $installs   = (int) $q1('SELECT COUNT(DISTINCT install) FROM events');
    $newToday   = (int) $q1('SELECT COUNT(*) FROM (SELECT install,MIN(ts) f FROM events GROUP BY install HAVING f>=?) t', [$now - 86400]);
    $active1    = (int) $q1('SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 86400]);
    $events1    = (int) $q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$now - 86400]);
    $crashes1   = (int) $q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE event=? AND ts>=?', ['crash', $now - 86400]);
    $fbSql = $db->prepare('SELECT COUNT(*) FROM feedback WHERE ts>=?');
    $fbSql->execute([$now - 86400]);
    $fb1 = (int) $fbSql->fetchColumn();

    $topRows = $db->prepare('SELECT event, SUM(cnt) c FROM events WHERE ts>=? GROUP BY event ORDER BY c DESC LIMIT 8');
    $topRows->execute([$now - 86400]);
    $top = $topRows->fetchAll(PDO::FETCH_ASSOC);

    $lines = [];
    $lines[] = 'ApneScan — daily summary (' . gmdate('d M Y', $now) . ' UTC)';
    $lines[] = str_repeat('-', 40);
    $lines[] = 'Total installs .......... ' . $installs;
    $lines[] = 'New installs (24h) ...... ' . $newToday;
    $lines[] = 'Active users (24h) ...... ' . $active1;
    $lines[] = 'Events (24h) ............ ' . $events1;
    $lines[] = 'Crashes (24h) ........... ' . $crashes1;
    $lines[] = 'New feedback (24h) ...... ' . $fb1;
    $lines[] = '';
    $lines[] = 'Top features (24h):';
    foreach ($top as $t) { $lines[] = '  ' . str_pad($t['event'], 22) . $t['c']; }
    $lines[] = '';
    $lines[] = 'Open dashboard: https://apnescan.subhashkaler.com/admin.php';

    $body = implode("\n", $lines);
    $host = parse_url('https://apnescan.subhashkaler.com', PHP_URL_HOST);
    $headers = 'From: ApneScan <noreply@' . $host . ">\r\n" . 'Content-Type: text/plain; charset=utf-8';
    @mail($email, 'ApneScan daily summary — ' . gmdate('d M', $now), $body, $headers);
    echo 'sent to ' . $email;
} catch (Throwable $e) {
    http_response_code(500); echo 'err';
}
