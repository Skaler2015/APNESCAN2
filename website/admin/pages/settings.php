<?php
/** Settings — app remote-control, summary email, security, API key, password. */
declare(strict_types=1);
echo flash_html();
$s = getset();
$apiKey = $s['api_key'] ?? '';

/** Renders the 2FA card for the current user (setup / enabled states). */
function twofa_section(): string {
    $u = current_user();
    [$secret, $enabled] = user_2fa($u['username']);
    $o = '<div class="sec" style="margin-top:24px">' . icon('shield') . 'Two-factor authentication</div>';
    if ($enabled) {
        return $o . '<p class="mut" style="font-size:12.5px;margin:0 0 10px"><span class="pill g">ON</span> Your account is protected with an authenticator app.</p>'
             . '<form method="post">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><button class="btn ghost" name="action" value="2fa_disable" onclick="return confirm(\'Disable 2FA?\')">Disable 2FA</button></form>';
    }
    if (!empty($_SESSION['setup_totp'])) {
        $sec = $_SESSION['setup_totp'];
        $uri = totp_uri($sec, $u['username']);
        return $o . '<p class="mut" style="font-size:12.5px;margin:0 0 8px">Add this key to Google Authenticator / Authy, then enter the 6-digit code to confirm.</p>'
             . '<div class="mono" style="font-size:13px;background:var(--surface2);padding:10px 12px;border-radius:9px;letter-spacing:2px;text-align:center">' . h($sec) . '</div>'
             . '<p class="faint" style="font-size:11px;margin:8px 0;word-break:break-all">' . h($uri) . '</p>'
             . '<form method="post" style="display:flex;gap:8px;align-items:flex-end">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings">'
             . '<div style="flex:1"><label class="fl">6-digit code</label><input class="inp" name="code" inputmode="numeric" placeholder="123456"></div>'
             . '<button class="btn" name="action" value="2fa_enable">Enable</button></form>';
    }
    return $o . '<p class="mut" style="font-size:12.5px;margin:0 0 10px"><span class="pill">OFF</span> Add a second layer of security with an authenticator app.</p>'
         . '<form method="post">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><button class="btn ghost" name="action" value="2fa_begin">' . icon('key') . 'Enable 2FA</button></form>';
}
echo '<div class="phead"><div><h1>Settings</h1><p>Control the app remotely and manage this dashboard</p></div></div>';

echo '<div class="grid g2" style="margin-top:16px">';

// App remote control
if (can('settings')) {
    echo '<div class="card pad"><form method="post">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><input type="hidden" name="action" value="savecfg">'
       . '<div class="ctitle">' . icon('msg') . 'Broadcast &amp; remote control</div><div class="csub">Pushed to every app on next launch</div>'
       . '<label class="fl">Broadcast banner (blank = none)</label><textarea class="inp" name="message" placeholder="e.g. New version available with faster scanning!">' . h($s['message'] ?? '') . '</textarea>'
       . '<label class="fl">Banner style</label><select class="inp" name="message_type">'
       . '<option value="info"' . (($s['message_type'] ?? 'info') === 'info' ? ' selected' : '') . '>Info</option>'
       . '<option value="success"' . (($s['message_type'] ?? '') === 'success' ? ' selected' : '') . '>Success</option>'
       . '<option value="warning"' . (($s['message_type'] ?? '') === 'warning' ? ' selected' : '') . '>Warning</option></select>'
       . '<label class="fl">Minimum required version</label><input class="inp" name="min_version" value="' . h($s['min_version'] ?? '') . '" placeholder="e.g. 1.0.72">'
       . '<div class="chk"><input type="checkbox" name="force_update" id="fu"' . (($s['force_update'] ?? '0') === '1' ? ' checked' : '') . '><label for="fu" style="margin:0">Force update — block older versions</label></div>'
       . '<label class="fl">Download URL</label><input class="inp" name="download_url" value="' . h($s['download_url'] ?? '') . '" placeholder="https://…/ApneScan-Setup.exe">'
       . '<label class="fl">Feature flags (JSON)</label><textarea class="inp" name="flags" placeholder=\'{"betaOcr": true}\'>' . h($s['flags'] ?? '{}') . '</textarea>'
       . '<label class="fl">Daily-summary email</label><input class="inp" name="admin_email" value="' . h($s['admin_email'] ?? '') . '" placeholder="you@example.com">'
       . '<label class="fl">Cron token (for the daily email / backups)</label><input class="inp" name="cron_token" value="' . h($s['cron_token'] ?? '') . '" placeholder="a random word">'
       . '<label class="fl">Alert webhook (Slack / Discord / generic)</label><input class="inp" name="webhook_url" value="' . h($s['webhook_url'] ?? '') . '" placeholder="https://hooks.slack.com/… or Discord webhook">'
       . '<label class="fl">Data retention (days, 0 = keep forever)</label><input class="inp" name="retention_days" type="number" value="' . h($s['retention_days'] ?? '0') . '" style="max-width:160px">'
       . '<div class="sec" style="margin-top:22px">' . icon('msg') . 'SMTP email (for the daily summary)</div>'
       . '<div class="csub" style="margin-bottom:4px">Leave host blank to use the server\'s default PHP mail().</div>'
       . '<label class="fl">SMTP host</label><input class="inp" name="smtp_host" value="' . h($s['smtp_host'] ?? '') . '" placeholder="smtp.hostinger.com">'
       . '<div style="display:flex;gap:10px"><div style="flex:1"><label class="fl">Port</label><input class="inp" name="smtp_port" value="' . h($s['smtp_port'] ?? '587') . '"></div>'
       . '<div style="flex:1"><label class="fl">Security</label><select class="inp" name="smtp_secure">'
       . '<option value="tls"' . (($s['smtp_secure'] ?? 'tls') === 'tls' ? ' selected' : '') . '>STARTTLS</option>'
       . '<option value="ssl"' . (($s['smtp_secure'] ?? '') === 'ssl' ? ' selected' : '') . '>SSL</option>'
       . '<option value="none"' . (($s['smtp_secure'] ?? '') === 'none' ? ' selected' : '') . '>None</option></select></div></div>'
       . '<label class="fl">SMTP username</label><input class="inp" name="smtp_user" value="' . h($s['smtp_user'] ?? '') . '" placeholder="you@apnescan.subhashkaler.com">'
       . '<label class="fl">SMTP password</label><input class="inp" type="password" name="smtp_pass" placeholder="' . ($s['smtp_pass'] ?? '' ? 'unchanged — leave blank to keep' : 'mailbox password') . '">'
       . '<div style="display:flex;gap:10px"><div style="flex:1"><label class="fl">From address</label><input class="inp" name="smtp_from" value="' . h($s['smtp_from'] ?? '') . '"></div>'
       . '<div style="flex:1"><label class="fl">From name</label><input class="inp" name="smtp_from_name" value="' . h($s['smtp_from_name'] ?? 'ApneScan') . '"></div></div>'
       . '<div style="margin-top:16px"><button class="btn">Save settings</button></div></form>'
       . '<form method="post" style="margin-top:12px">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><input type="hidden" name="action" value="test_email">'
       . '<div style="display:flex;gap:8px;align-items:flex-end"><div style="flex:1"><label class="fl">Send a test email to</label><input class="inp" name="to" value="' . h($s['admin_email'] ?? '') . '" placeholder="you@example.com"></div>'
       . '<button class="btn ghost">' . icon('msg') . 'Send test</button></div></form>'
       . '<form method="post" style="margin-top:10px">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><button class="btn ghost" name="action" value="test_webhook">' . icon('bell') . 'Test webhook alert</button></form></div>';
}

// Security + password
echo '<div class="card pad"><form method="post">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><input type="hidden" name="action" value="passwd">'
   . '<div class="ctitle">' . icon('key') . 'Change your password</div><div class="csub">For ' . h(current_user()['username']) . '</div>'
   . '<label class="fl">Current password</label><input class="inp" type="password" name="old">'
   . '<label class="fl">New password</label><input class="inp" type="password" name="n1" placeholder="6+ characters">'
   . '<label class="fl">Confirm new password</label><input class="inp" type="password" name="n2">'
   . '<div style="margin-top:16px"><button class="btn">Change password</button></div></form>'
   . twofa_section()
   . '<div class="sec" style="margin-top:24px">' . icon('shield') . 'Security</div>'
   . '<ul class="mut" style="font-size:12.5px;line-height:1.9;margin:0;padding-left:18px">'
   . '<li>CSRF tokens on every form</li><li>Prepared statements for all queries</li><li>Login rate-limiting (8 / 10 min)</li>'
   . '<li>Session regeneration on login</li><li>HttpOnly + SameSite cookies</li><li>Role-based permissions</li><li>Full audit log</li></ul>';
echo '<div class="sec" style="margin-top:24px">' . icon('zap') . 'REST API</div>'
   . '<p class="mut" style="font-size:12.5px;margin:0 0 8px">Read-only JSON at <span class="mono">admin/api/api.php?action=dashboard</span>. Session-auth, or append <span class="mono">&amp;key=</span> for scripts.</p>'
   . '<div class="mono" style="font-size:11.5px;background:var(--surface2);padding:8px 10px;border-radius:8px;word-break:break-all">api_key: ' . ($apiKey !== '' ? h($apiKey) : 'not set') . '</div>';
if (can('settings')) {
    echo '<form method="post" style="margin-top:10px;display:flex;gap:8px">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings">'
       . '<button class="btn ghost" name="action" value="gen_api_key">' . icon('refresh') . ($apiKey !== '' ? 'Rotate key' : 'Generate key') . '</button>'
       . ($apiKey !== '' ? '<button class="btn ghost" name="action" value="revoke_api_key" onclick="return confirm(\'Revoke API key?\')">Revoke</button>' : '') . '</form>';
}
echo '</div>';

echo '</div>';
