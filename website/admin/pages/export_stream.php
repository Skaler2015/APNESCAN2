<?php
/**
 * ApneScan Admin — download streamer. Handles ?do=csv|json|xml|report|backup.
 * Included by the front controller before any HTML output.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);
require_cap('export');
$do = $_GET['do'] ?? '';

function stream_rows_csv(array $header, iterable $rows): void {
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
}

if ($do === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="apnescan-events.csv"');
    $rows = [];
    foreach ($GLOBALS['db']->query('SELECT ts,event,version,os,cnt,install FROM events ORDER BY id DESC LIMIT 100000') as $r)
        $rows[] = [gmdate('Y-m-d H:i:s', (int)$r['ts']), $r['event'], $r['version'], $r['os'], $r['cnt'], substr($r['install'], 0, 10)];
    audit('export', 'events csv'); stream_rows_csv(['datetime_utc', 'event', 'version', 'os', 'count', 'install'], $rows); exit;
}

if ($do === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="apnescan-events.json"');
    $rows = $GLOBALS['db']->query('SELECT ts,event,version,os,cnt,install FROM events ORDER BY id DESC LIMIT 100000')->fetchAll();
    audit('export', 'events json'); echo json_encode(['exported' => gmdate('c'), 'count' => count($rows), 'events' => $rows], JSON_PRETTY_PRINT); exit;
}

if ($do === 'xml') {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="apnescan-events.xml"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<events>\n";
    foreach ($GLOBALS['db']->query('SELECT ts,event,version,os,cnt,install FROM events ORDER BY id DESC LIMIT 50000') as $r)
        echo '  <event ts="' . (int)$r['ts'] . '" name="' . h($r['event']) . '" version="' . h($r['version']) . '" os="' . h($r['os']) . '" count="' . (int)$r['cnt'] . '"/>' . "\n";
    echo "</events>\n"; audit('export', 'events xml'); exit;
}

if ($do === 'report') {
    $type = $_GET['type'] ?? 'daily'; $fmt = $_GET['fmt'] ?? 'csv'; $now = time();
    $spanMap = ['daily' => 86400, 'weekly' => 7 * 86400, 'monthly' => 30 * 86400, 'yearly' => 365 * 86400];
    $since = $now - ($spanMap[$type] ?? 86400);
    if ($type === 'scanner') $data = scanner_sources();
    elseif ($type === 'ocr') $data = [['metric' => 'ocr_runs', 'value' => ocr_totals()['runs']], ['metric' => 'ocr_users', 'value' => ocr_totals()['onusers']]];
    elseif ($type === 'crash') $data = qa('SELECT version, SUM(cnt) crashes FROM events WHERE event=\'crash\' GROUP BY version ORDER BY crashes DESC');
    elseif ($type === 'feature') $data = qa('SELECT event feature, SUM(cnt) uses, COUNT(DISTINCT install) users FROM events GROUP BY event ORDER BY uses DESC');
    else $data = qa('SELECT event feature, SUM(cnt) uses FROM events WHERE ts>=? GROUP BY event ORDER BY uses DESC', [$since]);
    audit('report', $type . ' ' . $fmt);
    if ($fmt === 'json') {
        header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="apnescan-' . $type . '-report.json"');
        echo json_encode(['report' => $type, 'generated' => gmdate('c'), 'rows' => $data], JSON_PRETTY_PRINT); exit;
    }
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="apnescan-' . $type . '-report.csv"');
    stream_rows_csv($data ? array_keys($data[0]) : ['metric'], array_map('array_values', $data)); exit;
}

if ($do === 'backup') {
    require_cap('backup');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="apnescan-backup-' . gmdate('Ymd-His') . '.sql"');
    $tables = ['events', 'settings', 'feedback', 'geo', 'devices', 'admin_users', 'audit_log'];
    echo "-- ApneScan analytics backup " . gmdate('c') . "\nSET NAMES utf8mb4;\n\n";
    foreach ($tables as $t) {
        try {
            $create = $GLOBALS['db']->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM);
            echo "DROP TABLE IF EXISTS `$t`;\n" . ($create[1] ?? '') . ";\n";
            foreach ($GLOBALS['db']->query('SELECT * FROM `' . $t . '`') as $row) {
                $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $GLOBALS['db']->quote((string)$v), array_values($row));
                echo 'INSERT INTO `' . $t . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
            echo "\n";
        } catch (Throwable $e) { echo "-- skip $t: " . $e->getMessage() . "\n"; }
    }
    audit('backup', 'sql dump'); exit;
}

if ($do === 'backupfile') {
    require_cap('backup');
    $p = backup_path($_GET['f'] ?? '');
    if (!$p) { http_response_code(404); echo 'Not found.'; exit; }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($p) . '"');
    audit('backup_download', basename($p));
    readfile($p); exit;
}

http_response_code(400); echo 'Unknown export.';
