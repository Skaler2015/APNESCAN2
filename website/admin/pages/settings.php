<?php
/** Settings — app remote-control, summary email, security, API key, password. */
declare(strict_types=1);
echo flash_html();
$s = getset();
$apiKey = $s['api_key'] ?? '';
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
       . '<label class="fl">Data retention (days, 0 = keep forever)</label><input class="inp" name="retention_days" type="number" value="' . h($s['retention_days'] ?? '0') . '" style="max-width:160px">'
       . '<div style="margin-top:16px"><button class="btn">Save settings</button></div></form></div>';
}

// Security + password
echo '<div class="card pad"><form method="post">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=settings"><input type="hidden" name="action" value="passwd">'
   . '<div class="ctitle">' . icon('key') . 'Change your password</div><div class="csub">For ' . h(current_user()['username']) . '</div>'
   . '<label class="fl">Current password</label><input class="inp" type="password" name="old">'
   . '<label class="fl">New password</label><input class="inp" type="password" name="n1" placeholder="6+ characters">'
   . '<label class="fl">Confirm new password</label><input class="inp" type="password" name="n2">'
   . '<div style="margin-top:16px"><button class="btn">Change password</button></div></form>'
   . '<div class="sec" style="margin-top:24px">' . icon('shield') . 'Security</div>'
   . '<ul class="mut" style="font-size:12.5px;line-height:1.9;margin:0;padding-left:18px">'
   . '<li>CSRF tokens on every form</li><li>Prepared statements for all queries</li><li>Login rate-limiting (8 / 10 min)</li>'
   . '<li>Session regeneration on login</li><li>HttpOnly + SameSite cookies</li><li>Role-based permissions</li><li>Full audit log</li></ul>';
if ($apiKey !== '' || can('settings')) {
    echo '<div class="sec" style="margin-top:24px">' . icon('zap') . 'REST API</div>'
       . '<p class="mut" style="font-size:12.5px;margin:0 0 8px">Read-only JSON at <span class="mono">admin/api/api.php?action=dashboard</span>. Session-auth, or add <span class="mono">&amp;key=</span> below.</p>'
       . '<div class="mono" style="font-size:11.5px;background:var(--surface2);padding:8px 10px;border-radius:8px">api_key: ' . ($apiKey !== '' ? h($apiKey) : 'not set — add "api_key" via DB to enable') . '</div>';
}
echo '</div>';

echo '</div>';
