<?php
/**
 * ApneScan Admin — database backup helpers (SQL dump to the protected data dir).
 * Used by the Backup page, the backup_now action, and api/backup-cron.php.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

function backups_dir(): string {
    $d = data_dir() . '/backups';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/** Build a full SQL dump of the analytics tables as a string. */
function make_backup_sql(PDO $db): string {
    $tables = ['events', 'settings', 'feedback', 'geo', 'devices', 'admin_users', 'audit_log'];
    $out = "-- ApneScan analytics backup " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $t) {
        try {
            $create = $db->query('SHOW CREATE TABLE `' . $t . '`')->fetch(PDO::FETCH_NUM);
            $out .= "DROP TABLE IF EXISTS `$t`;\n" . ($create[1] ?? '') . ";\n";
            foreach ($db->query('SELECT * FROM `' . $t . '`') as $row) {
                $cols = array_map(fn($c) => '`' . $c . '`', array_keys($row));
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), array_values($row));
                $out .= 'INSERT INTO `' . $t . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
            $out .= "\n";
        } catch (Throwable $e) { $out .= "-- skip $t: " . $e->getMessage() . "\n"; }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

/** Write a gzipped backup file to disk and prune to the newest $keep. */
function write_backup_file(PDO $db, int $keep = 20): string {
    $sql = make_backup_sql($db);
    $name = 'apnescan-' . gmdate('Ymd-His') . '.sql.gz';
    $path = backups_dir() . '/' . $name;
    @file_put_contents($path, function_exists('gzencode') ? gzencode($sql, 6) : $sql);
    prune_backups($keep);
    return $name;
}

function list_backups(): array {
    $out = [];
    foreach (glob(backups_dir() . '/apnescan-*.sql*') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int)@filesize($f), 'mtime' => (int)@filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

function prune_backups(int $keep): void {
    $files = list_backups();
    foreach (array_slice($files, $keep) as $f) @unlink(backups_dir() . '/' . $f['name']);
}

/** Safe path for a named backup (prevents traversal). */
function backup_path(string $name): ?string {
    $name = basename($name);
    if (!preg_match('/^apnescan-[0-9\-]+\.sql(\.gz)?$/', $name)) return null;
    $p = backups_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}
