<?php
/**
 * ApneScan Admin — internal REST-ish JSON API for live widgets and integrations.
 * Session-authenticated (same cookie as the dashboard) OR api_key in settings.
 *
 *   GET ?action=live       live KPIs + recent events
 *   GET ?action=dashboard  overview KPIs
 *   GET ?action=events     recent events (paginated: &pg=)
 *   GET ?action=versions   version distribution
 *   GET ?action=scanner    scanner sources
 *   GET ?action=users      top installs
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require dirname(__DIR__) . '/includes/init.php';
require dirname(__DIR__) . '/includes/metrics.php';

// ---- Auth: session, or ?key= matching settings.api_key --------------------
$ok = is_logged_in();
if (!$ok) {
    $key = $_GET['key'] ?? '';
    $stored = setting('api_key', '');
    if ($stored !== '' && hash_equals($stored, (string)$key)) $ok = true;
}
if (!$ok) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'unauthorized']); exit; }

$action = $_GET['action'] ?? 'dashboard';
$now = time();

switch ($action) {
    case 'live':
        $m = metrics_overview(resolve_range('1'));
        $rows = qa('SELECT ts,event,version,install FROM events ORDER BY id DESC LIMIT 25');
        $ev = array_map(fn($r) => [
            'when' => gmdate('d M · H:i:s', (int)$r['ts']), 'event' => $r['event'],
            'version' => $r['version'], 'install' => $r['install'],
        ], $rows);
        echo json_encode(['ok' => true, 'kpis' => [
            'online' => $m['online'], 'sessions' => $m['sessions'],
            'active_today' => $m['active_today'], 'events' => $m['events'],
        ], 'events' => $ev]);
        break;

    case 'dashboard':
        echo json_encode(['ok' => true, 'kpis' => metrics_overview(resolve_range($_GET['r'] ?? '30'))]);
        break;

    case 'events':
        $pg = max(1, (int)($_GET['pg'] ?? 1));
        echo json_encode(['ok' => true] + events_page(['q' => $_GET['q'] ?? ''], $pg));
        break;

    case 'versions':
        echo json_encode(['ok' => true, 'versions' => version_active()]);
        break;

    case 'scanner':
        echo json_encode(['ok' => true, 'sources' => scanner_sources(), 'pages' => pages_scanned_total()]);
        break;

    case 'users':
        echo json_encode(['ok' => true, 'top' => top_installs(0, 20)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unknown action']);
}
