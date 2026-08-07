<?php
/** Backup — on-demand + saved-to-disk backups with restore guidance. */
declare(strict_types=1);
echo flash_html();
$token = setting('cron_token', '');
$files = list_backups();
echo '<div class="phead"><div><h1>' . h(t('Backup')) . '</h1><p>' . h(t('sub_backup')) . '</p></div></div>';

echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('backup') . 'Create a backup</div><div class="csub">Download now, or save a copy on the server</div>'
   . '<div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="admin.php?do=backup">' . icon('download') . 'Download SQL</a>'
   . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=backup"><button class="btn ghost" name="action" value="backup_now">' . icon('save') . 'Save on server</button></form></div>'
   . '<p class="csub" style="margin-top:14px">Saved backups are gzipped and kept (latest 20) in a web-protected folder.</p></div>'
   . '<div class="card pad"><div class="ctitle">' . icon('clock') . 'Scheduled backup</div><div class="csub">Automate a daily save with Hostinger cron</div>'
   . '<p class="mut" style="font-size:13px;margin:14px 0 8px">Add this cron job (set a cron token in Settings first):</p>'
   . '<code class="mono" style="display:block;background:var(--surface2);padding:12px;border-radius:10px;font-size:11.5px;overflow:auto">wget -q -O /dev/null "' . h(SITE_URL) . '/api/backup-cron.php?token=' . h($token ?: 'YOUR_TOKEN') . '"</code>'
   . '<p class="csub" style="margin-top:12px">Restore from Hostinger → phpMyAdmin → Import (gunzip first).</p></div>'
   . '</div>';

echo '<div class="sec">' . icon('db') . 'Saved backups</div><div class="card"><table class="tbl"><thead><tr><th>File</th><th>Created (IST)</th><th class="num">Size</th><th></th></tr></thead><tbody>';
foreach ($files as $f) {
    echo '<tr><td class="mono">' . h($f['name']) . '</td><td class="mut">' . h(dt($f['mtime'], 'd M Y · H:i')) . '</td>'
       . '<td class="num">' . human_bytes($f['size']) . '</td>'
       . '<td><a class="btn ghost" style="padding:3px 10px;font-size:11px" href="admin.php?do=backupfile&f=' . h(rawurlencode($f['name'])) . '">Download</a></td></tr>';
}
if (!$files) echo '<tr><td colspan="4" class="empty">No saved backups yet — click “Save on server”.</td></tr>';
echo '</tbody></table></div>';
