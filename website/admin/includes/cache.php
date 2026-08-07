<?php
/**
 * ApneScan Admin — tiny file-based cache for expensive metrics. Keeps the
 * dashboard snappy without a cache server. Stored under a protected data dir.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

/** Absolute path to the writable, web-protected data directory. */
function data_dir(): string {
    $dir = SITE_ROOT . '/api/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        // Belt-and-braces: deny web access even if the parent .htaccess changes.
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/** Read a cached value or null if missing/expired. */
function cache_get(string $key) {
    $f = data_dir() . '/cache_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    if (!is_array($d) || ($d['exp'] ?? 0) < time()) return null;
    return $d['val'] ?? null;
}

/** Store a value with a TTL (seconds). */
function cache_set(string $key, $val, int $ttl = 60): void {
    $f = data_dir() . '/cache_' . preg_replace('/[^a-z0-9_]/i', '', $key) . '.json';
    @file_put_contents($f, json_encode(['exp' => time() + $ttl, 'val' => $val]), LOCK_EX);
}

/** Return cached value or compute+store it. */
function remember(string $key, int $ttl, callable $fn) {
    $v = cache_get($key);
    if ($v !== null) return $v;
    $v = $fn();
    cache_set($key, $v, $ttl);
    return $v;
}

/** Drop all cache files (called after data-mutating actions). */
function cache_flush(): void {
    foreach (glob(data_dir() . '/cache_*.json') ?: [] as $f) @unlink($f);
}
