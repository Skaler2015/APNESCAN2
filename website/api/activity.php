<?php
// ApneScan — public aggregate activity feed. Returns the same "Activity"
// breakdown the admin dashboard shows (Scan, PDF Save, Rename, Print, Import,
// Share) with all-time Total and last-24h Today, summed across every install,
// so the desktop app can display the same world numbers. Read-only, anonymous.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$cfg = __DIR__ . '/config.php';
if (!file_exists($cfg)) { echo json_encode(['ok' => false]); exit; }
require $cfg;

try {
    $db = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $now = time();

    $all = [];
    foreach ($db->query('SELECT event, SUM(cnt) c FROM events GROUP BY event') as $r) $all[$r['event']] = (int)$r['c'];
    // "Today" = since local (IST, +5:30) midnight, so the column resets each
    // night instead of being a rolling 24-hour window.
    $istOffset = 19800; // +05:30
    $istMidnight = (int)(floor(($now + $istOffset) / 86400) * 86400 - $istOffset);
    $tod = [];
    $s = $db->prepare('SELECT event, SUM(cnt) c FROM events WHERE ts>=? GROUP BY event');
    $s->execute([$istMidnight]);
    foreach ($s as $r) $tod[$r['event']] = (int)$r['c'];

    $groups = [
        ['Scan',        ['scan'], '#16a34a'],
        ['Pages scanned', ['pages_scanned'], '#0ea5e9'],
        ['Blank skipped', ['blank_skipped'], '#64748b'],
        ['PDF Save',    ['savePdf', 'savePdfSelected', 'savePdfHere'], '#2563eb'],
        ['Rename',      ['renameItem', 'renamePage'], '#dc2626'],
        ['Print',       ['print', 'printFile'], '#d97706'],
        ['Import',      ['import', 'importPath', 'importDropped'], '#7c3aed'],
        ['Share',       ['share', 'shareWhatsapp', 'shareWindows', 'sharePhone'], '#0891b2'],
    ];
    $out = [];
    foreach ($groups as $g) {
        $tt = 0; $td = 0;
        foreach ($g[1] as $e) { $tt += $all[$e] ?? 0; $td += $tod[$e] ?? 0; }
        $out[] = ['label' => $g[0], 'total' => $tt, 'today' => $td, 'color' => $g[2]];
    }
    usort($out, fn($a, $b) => $b['today'] <=> $a['today']);
    echo json_encode(['ok' => true, 'items' => $out]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false]);
}
