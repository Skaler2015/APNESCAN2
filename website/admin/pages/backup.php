<?php
/** Backup — download a full SQL dump; scheduling guidance. */
declare(strict_types=1);
echo flash_html();
$token = setting('cron_token', '');
echo '<div class="phead"><div><h1>Backup</h1><p>Protect your analytics data with on-demand and scheduled backups</p></div></div>';
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('backup') . 'Manual backup</div><div class="csub">Download a complete SQL dump right now</div>'
   . '<div style="margin-top:16px"><a class="btn" href="admin.php?do=backup">' . icon('download') . 'Download SQL backup</a></div>'
   . '<p class="csub" style="margin-top:14px">Includes events, settings, feedback, devices, admins and audit log.</p></div>'
   . '<div class="card pad"><div class="ctitle">' . icon('clock') . 'Scheduled backup</div><div class="csub">Automate with a Hostinger cron job</div>'
   . '<p class="mut" style="font-size:13px;margin:14px 0 8px">Add a weekly cron that saves a dump to your account:</p>'
   . '<code class="mono" style="display:block;background:var(--surface2);padding:12px;border-radius:10px;font-size:11.5px;overflow:auto">mysqldump -u ' . h($GLOBALS['DB_USER']) . ' -p\'YOUR_PW\' ' . h($GLOBALS['DB_NAME']) . ' &gt; ~/backups/apnescan-$(date +\\%F).sql</code>'
   . '<p class="csub" style="margin-top:12px">Restore anytime from Hostinger → Databases → phpMyAdmin → Import.</p></div>'
   . '</div>';
