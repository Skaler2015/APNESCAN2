<?php
/** Help — quick documentation for the dashboard. */
declare(strict_types=1);
echo '<div class="phead"><div><h1>Help</h1><p>How this dashboard works</p></div></div>';
$items = [
    ['grid', 'Overview & Analytics', 'KPI cards and interactive charts summarise installs, activity, retention and geography. Use the range chips (Today … All time) to change the window.'],
    ['scan', 'Scanner & OCR', 'Track how documents are captured and how OCR is used. Deeper hardware/timing metrics fill in as users update the app.'],
    ['pulse', 'Events & Feedback', 'A searchable, filterable, paginated log of every action, plus the in-app feedback inbox. Click any install ID to see its full history.'],
    ['bell', 'Notifications', 'Automatic alerts for crashes, high error rates, unread feedback and large database size.'],
    ['role', 'Admins & Roles', 'Add more admins with roles: Admin, Manager, Operator, Viewer. The bootstrap “admin” is the super admin.'],
    ['settings', 'App control', 'From Settings you can broadcast a banner to all apps, require a minimum version (force-update), toggle feature flags, and set up the daily email.'],
    ['download', 'Export & Backup', 'Download events as CSV/JSON/XML, generate topic reports, or take a full SQL backup.'],
    ['shield', 'Security', 'CSRF, prepared statements, login rate-limiting, session hardening, role permissions and a full audit log are built in.'],
];
echo '<div class="grid g2" style="margin-top:16px">';
foreach ($items as $it) echo '<div class="card pad"><div class="ctitle">' . icon($it[0]) . h($it[1]) . '</div><p class="mut" style="font-size:13px;line-height:1.6;margin:10px 0 0">' . h($it[2]) . '</p></div>';
echo '</div>';
echo '<p class="faint" style="font-size:12px;margin-top:20px">🔒 Anonymous usage only — no document content, filenames or personal data is ever collected.</p>';
