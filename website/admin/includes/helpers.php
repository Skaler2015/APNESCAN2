<?php
/**
 * ApneScan Admin — shared helpers: escaping, formatting, DB shortcuts, CSRF,
 * auth, roles, audit logging and rate limiting.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

// ---- Output / formatting ---------------------------------------------------
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n): string { return number_format((float)$n); }
function pct($a, $b): int { return $b > 0 ? (int)round($a / $b * 100) : 0; }
function human_bytes($b): string {
    $b = (float)$b; $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $b < 10 && $i > 0 ? 1 : 0) . ' ' . $u[$i];
}
// India Standard Time (UTC+5:30, no DST). Format a UTC epoch for display in IST.
const IST_OFFSET = 19800;
function dt($ts, string $fmt = 'd M Y · H:i'): string { return gmdate($fmt, (int)$ts + IST_OFFSET); }

function human_ms($ms): string {
    $ms = (float)$ms; if ($ms <= 0) return '—';
    return $ms < 1000 ? round($ms) . ' ms' : round($ms / 1000, 1) . ' s';
}
function ago($ts): string {
    $d = time() - (int)$ts; if ($d < 60) return $d . 's ago';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
}
function flag($cc): string {
    if (!preg_match('/^[A-Za-z]{2}$/', (string)$cc)) return '🏳️';
    $cc = strtoupper($cc);
    return mb_chr(127397 + ord($cc[0]), 'UTF-8') . mb_chr(127397 + ord($cc[1]), 'UTF-8');
}

// ---- DB shortcuts (prepared statements everywhere) -------------------------
function q1($sql, array $a = []) { $s = $GLOBALS['db']->prepare($sql); $s->execute($a); return $s->fetchColumn(); }
function qa($sql, array $a = []): array { $s = $GLOBALS['db']->prepare($sql); $s->execute($a); return $s->fetchAll(); }
function qr($sql, array $a = []) { $s = $GLOBALS['db']->prepare($sql); $s->execute($a); return $s->fetch(); }
function getset(): array {
    static $c = null;
    if ($c === null) $c = $GLOBALS['db']->query('SELECT k,v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $c;
}
function setting($k, $default = '') { $s = getset(); return $s[$k] ?? $default; }
function setset($k, $v): void {
    $st = $GLOBALS['db']->prepare('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
    $st->execute([$k, (string)$v]);
}

// ---- CSRF ------------------------------------------------------------------
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(CSRF) . '">'; }
function csrf_ok(): bool { return isset($_POST['csrf']) && hash_equals(CSRF, (string)$_POST['csrf']); }
function require_csrf(): void { if (!csrf_ok()) { http_response_code(400); exit('Invalid CSRF token.'); } }

// ---- Auth / roles ----------------------------------------------------------
function current_user(): ?array {
    if (empty($_SESSION['auth'])) return null;
    return ['username' => $_SESSION['username'] ?? 'admin', 'role' => $_SESSION['role'] ?? 'viewer'];
}
function is_logged_in(): bool { return !empty($_SESSION['auth']); }
function role(): string { return $_SESSION['role'] ?? 'viewer'; }
function can(string $cap): bool {
    $caps = $GLOBALS['ROLE_CAPS'][role()] ?? [];
    return in_array('*', $caps, true) || in_array($cap, $caps, true);
}
function require_cap(string $cap): void {
    if (!can($cap)) { http_response_code(403); exit('You do not have permission for this action.'); }
}

/** Verify a username/password against admin_users, falling back to the config
 *  bootstrap super-admin ("admin" + api/config.php hash). */
function verify_login(string $user, string $pass): ?array {
    global $ADMIN_HASH;
    $row = qr('SELECT username,pass_hash,role FROM admin_users WHERE username=?', [$user]);
    if ($row && password_verify($pass, $row['pass_hash'])) {
        return ['username' => $row['username'], 'role' => $row['role']];
    }
    // Bootstrap super admin (compatible with the original single-password setup)
    if ($user === 'admin' && !empty($ADMIN_HASH) && password_verify($pass, $ADMIN_HASH)) {
        return ['username' => 'admin', 'role' => 'super_admin'];
    }
    return null;
}

// ---- Rate limiting (login) -------------------------------------------------
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function login_blocked(): bool {
    $n = (int) q1('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND ts>=?', [client_ip(), time() - 600]);
    return $n >= 8; // 8 tries / 10 min
}
function record_login_fail(): void {
    $s = $GLOBALS['db']->prepare('INSERT INTO login_attempts (ip,ts) VALUES (?,?)');
    $s->execute([client_ip(), time()]);
    // opportunistic cleanup
    $GLOBALS['db']->prepare('DELETE FROM login_attempts WHERE ts<?')->execute([time() - 86400]);
}
function clear_login_fails(): void {
    $GLOBALS['db']->prepare('DELETE FROM login_attempts WHERE ip=?')->execute([client_ip()]);
}

// ---- Flash messages (survive a redirect) -----------------------------------
function set_flash(string $m, string $t = 'ok'): void { $_SESSION['flash'] = ['m' => $m, 't' => $t]; }
function flash_html(): string {
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    return $f ? '<div class="flash ' . h($f['t']) . '">' . h($f['m']) . '</div>' : '';
}
function redirect(string $to): void { header('Location: ' . $to); exit; }

// ---- Webhook alerts (Slack / Discord / generic) ----------------------------
function send_webhook(string $url, string $text): bool {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $isDiscord = strpos($url, 'discord.com') !== false || strpos($url, 'discordapp.com') !== false;
    $payload = $isDiscord ? ['content' => $text] : ['text' => $text];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($payload), 'timeout' => 6, 'ignore_errors' => true,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}
/** Post bad/warn notifications to the configured webhook, throttled hourly. */
function fire_webhook_alerts(array $notifs): void {
    $url = setting('webhook_url', '');
    if ($url === '') return;
    foreach ($notifs as $n) {
        if (!in_array($n['sev'] ?? '', ['bad', 'warn'], true)) continue;
        $key = 'alert_' . preg_replace('/[^a-z0-9]/i', '', $n['t'] ?? 'x');
        if (cache_get($key) !== null) continue;                 // already alerted this hour
        if (send_webhook($url, '⚠ ApneScan alert: ' . $n['title'] . ' — ' . $n['sub'])) cache_set($key, 1, 3600);
    }
}

// ---- Audit log -------------------------------------------------------------
function audit(string $action, string $detail = ''): void {
    try {
        $u = current_user();
        $s = $GLOBALS['db']->prepare('INSERT INTO audit_log (user,role,action,detail,ip,ts) VALUES (?,?,?,?,?,?)');
        $s->execute([$u['username'] ?? '-', $u['role'] ?? '-', $action, mb_substr($detail, 0, 255), client_ip(), time()]);
    } catch (Throwable $e) { /* non-fatal */ }
}
