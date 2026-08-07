<?php
/**
 * ApneScan — dependency-free mailer. Uses SMTP (with AUTH LOGIN + optional
 * STARTTLS/SSL) when configured, otherwise falls back to PHP mail(). Shared by
 * the admin dashboard (test email) and summary.php (daily report).
 *
 * $s keys: smtp_host, smtp_port, smtp_secure(tls|ssl|none), smtp_user,
 *          smtp_pass, smtp_from, smtp_from_name
 *
 * @return array{0:bool,1:string}  [ok, detail]
 */
declare(strict_types=1);

function apnescan_send_mail(array $s, string $to, string $subject, string $body): array
{
    $host     = trim($s['smtp_host'] ?? '');
    $fromName = trim($s['smtp_from_name'] ?? '') ?: 'ApneScan';
    $from     = trim($s['smtp_from'] ?? '') ?: ('noreply@' . preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return [false, 'invalid recipient'];

    // Fallback: PHP mail()
    if ($host === '') {
        $headers = 'From: ' . $fromName . ' <' . $from . ">\r\n" . 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
        return [@mail($to, $subject, $body, $headers), 'php mail()'];
    }

    $port   = (int)($s['smtp_port'] ?? 587);
    $secure = $s['smtp_secure'] ?? 'tls';
    $user   = (string)($s['smtp_user'] ?? '');
    $pass   = (string)($s['smtp_pass'] ?? '');
    $ehlo   = 'EHLO ' . preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $errno = 0; $errstr = '';
    $fp = @fsockopen(($secure === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 15);
    if (!$fp) return [false, "connect failed: $errstr"];
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $data;
    };
    $cmd = function (?string $c) use ($fp, $read) { if ($c !== null) fwrite($fp, $c . "\r\n"); return $read(); };

    $read();                       // server greeting
    $cmd($ehlo);
    if ($secure === 'tls') {
        $cmd('STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return [false, 'STARTTLS failed']; }
        $cmd($ehlo);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (strpos($r, '235') === false) { $cmd('QUIT'); fclose($fp); return [false, 'authentication failed']; }
    }
    $cmd("MAIL FROM:<$from>");
    $cmd("RCPT TO:<$to>");
    $cmd('DATA');
    $headers = "From: $fromName <$from>\r\nTo: <$to>\r\nSubject: " . mb_encode_mimeheader($subject, 'UTF-8')
             . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";
    // dot-stuff to keep a lone "." from ending DATA early
    $safe = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $body));
    $safe = str_replace("\n", "\r\n", $safe);
    $r = $cmd($headers . "\r\n" . $safe . "\r\n.");
    $ok = strpos($r, '250') !== false;
    $cmd('QUIT');
    fclose($fp);
    return [$ok, $ok ? 'sent via SMTP' : 'server rejected message'];
}
