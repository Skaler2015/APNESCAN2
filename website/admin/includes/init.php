<?php
/**
 * ApneScan Enterprise Admin — bootstrap.
 * Loads config, hardens the session, opens the DB, ensures schema, and exposes
 * auth / role / CSRF / audit helpers. Every admin page includes this first.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

// ---- Session hardening -----------------------------------------------------
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'secure' => $secure, 'samesite' => 'Lax',
    ]);
    session_name('APNESCAN_ADMIN');
    session_start();
}

// ---- Paths + config --------------------------------------------------------
define('ADMIN_ROOT', dirname(__DIR__));               // website/admin
define('SITE_ROOT', dirname(ADMIN_ROOT));             // website
define('CONFIG_FILE', SITE_ROOT . '/api/config.php'); // shared with track.php
define('APP_DOWNLOAD_URL', 'https://github.com/Skaler2015/APNESCAN2/releases/download/apnescan-latest/ApneScan-Setup.exe');
define('SITE_URL', 'https://apnescan.subhashkaler.com');

// Roles → capabilities. '*' means everything.
$GLOBALS['ROLE_CAPS'] = [
    'super_admin' => ['*'],
    'admin'       => ['view', 'export', 'controls', 'reports', 'notifications', 'backup', 'settings', 'audit', 'users'],
    'manager'     => ['view', 'export', 'reports', 'notifications'],
    'operator'    => ['view', 'controls'],
    'viewer'      => ['view'],
];
$GLOBALS['ROLE_LABELS'] = [
    'super_admin' => 'Super Admin', 'admin' => 'Admin', 'manager' => 'Manager',
    'operator' => 'Operator', 'viewer' => 'Viewer',
];

require_once __DIR__ . '/helpers.php';

// ---- First-run: config missing -> hand off to setup wizard ----------------
if (!file_exists(CONFIG_FILE)) {
    require ADMIN_ROOT . '/auth/setup.php';
    exit;
}
require CONFIG_FILE; // $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS,$ADMIN_HASH

// ---- Database --------------------------------------------------------------
/** @var PDO $db */
try {
    $db = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Check api/config.php.');
}
$GLOBALS['db'] = $db;

ensure_schema($db);

// ---- CSRF ------------------------------------------------------------------
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
define('CSRF', $_SESSION['csrf']);

/**
 * Ensure every table this dashboard relies on exists (idempotent).
 */
function ensure_schema(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS events (id BIGINT AUTO_INCREMENT PRIMARY KEY,install VARCHAR(40),event VARCHAR(40),version VARCHAR(20),os VARCHAR(40),cnt INT,iphash VARCHAR(16),ts INT,day CHAR(10),INDEX idx_ts(ts),INDEX idx_install(install),INDEX idx_event(event),INDEX idx_ver(version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(40) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS live (install VARCHAR(40) PRIMARY KEY, ts INT, version VARCHAR(20)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS geo (install VARCHAR(40) PRIMARY KEY, country CHAR(2), ts INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS feedback (id BIGINT AUTO_INCREMENT PRIMARY KEY,install VARCHAR(40),version VARCHAR(20),contact VARCHAR(120),message TEXT,ts INT,day CHAR(10),seen TINYINT DEFAULT 0,INDEX idx_ts(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Device profiles (populated by the app from Phase 2 onward).
    $db->exec("CREATE TABLE IF NOT EXISTS devices (install VARCHAR(40) PRIMARY KEY,os VARCHAR(40),arch VARCHAR(16),cpu_cores INT,ram_mb INT,screen VARCHAR(24),monitors INT,lang VARCHAR(16),tz VARCHAR(40),scanner VARCHAR(80),ts INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Multiple admins with roles.
    $db->exec("CREATE TABLE IF NOT EXISTS admin_users (username VARCHAR(40) PRIMARY KEY,pass_hash VARCHAR(255),role VARCHAR(20),created INT,last_login INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Audit + login-attempt logs.
    $db->exec("CREATE TABLE IF NOT EXISTS audit_log (id BIGINT AUTO_INCREMENT PRIMARY KEY,user VARCHAR(40),role VARCHAR(20),action VARCHAR(40),detail VARCHAR(255),ip VARCHAR(64),ts INT,INDEX idx_ts(ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (id BIGINT AUTO_INCREMENT PRIMARY KEY,ip VARCHAR(64),ts INT,INDEX idx_ip_ts(ip,ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
