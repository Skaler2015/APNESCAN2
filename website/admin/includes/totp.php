<?php
/**
 * ApneScan Admin — RFC 6238 TOTP (Google Authenticator compatible), pure PHP.
 * Plus helpers to read/write a user's 2FA secret (bootstrap admin → settings,
 * other admins → admin_users columns).
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_secret(int $len = 16): string {
    $s = ''; for ($i = 0; $i < $len; $i++) $s .= TOTP_ALPHABET[random_int(0, 31)]; return $s;
}
function base32_decode(string $b32): string {
    $b32 = strtoupper($b32); $bits = ''; $out = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $v = strpos(TOTP_ALPHABET, $b32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) $out .= chr((int)bindec(substr($bits, $i, 8)));
    return $out;
}
function totp_code(string $secret, ?int $t = null): string {
    $key = base32_decode($secret);
    $counter = intdiv($t ?? time(), 30);
    $bin = pack('N*', 0) . pack('N*', $counter);          // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $bin, $key, true);
    $off = ord($hash[19]) & 0xf;
    $code = ((ord($hash[$off]) & 0x7f) << 24) | ((ord($hash[$off + 1]) & 0xff) << 16)
          | ((ord($hash[$off + 2]) & 0xff) << 8) | (ord($hash[$off + 3]) & 0xff);
    return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
}
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + $i * 30), $code)) return true;
    }
    return false;
}
function totp_uri(string $secret, string $account, string $issuer = 'ApneScan'): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/** @return array{0:string,1:bool} [secret, enabled] */
function user_2fa(string $user): array {
    if ($user === 'admin') return [setting('totp_admin_secret', ''), setting('totp_admin_enabled', '0') === '1'];
    $r = qr('SELECT totp_secret,totp_enabled FROM admin_users WHERE username=?', [$user]);
    return [$r['totp_secret'] ?? '', (int)($r['totp_enabled'] ?? 0) === 1];
}
function set_user_2fa(string $user, string $secret, bool $enabled): void {
    if ($user === 'admin') {
        setset('totp_admin_secret', $secret);
        setset('totp_admin_enabled', $enabled ? '1' : '0');
    } else {
        $GLOBALS['db']->prepare('UPDATE admin_users SET totp_secret=?, totp_enabled=? WHERE username=?')
            ->execute([$secret, $enabled ? 1 : 0, $user]);
    }
}
