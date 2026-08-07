<?php
/** Audit Log — security-relevant admin actions (paginated). */
declare(strict_types=1);
$pg = max(1, (int)($_GET['pg'] ?? 1)); $per = 40; $off = ($pg - 1) * $per;
$total = (int) q1('SELECT COUNT(*) FROM audit_log');
$rows = qa("SELECT user,role,action,detail,ip,ts FROM audit_log ORDER BY id DESC LIMIT $per OFFSET $off");
echo '<div class="phead"><div><h1>Audit Log</h1><p>Logins, exports, deletions, settings &amp; password changes</p></div></div>';
echo '<div class="card" style="margin-top:16px"><table class="tbl"><thead><tr><th>When (UTC)</th><th>User</th><th>Role</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $sev = in_array($r['action'], ['login_failed', 'feedback_delete', 'user_del', 'archive'], true) ? 'r' : (in_array($r['action'], ['login', 'password_change', 'settings_change'], true) ? 'w' : '');
    echo '<tr><td class="mut">' . h(gmdate('d M Y · H:i', (int)$r['ts'])) . '</td><td><b>' . h($r['user']) . '</b></td>'
       . '<td class="mut">' . h($GLOBALS['ROLE_LABELS'][$r['role']] ?? $r['role']) . '</td><td><span class="pill ' . $sev . '">' . h($r['action']) . '</span></td>'
       . '<td class="mut">' . h($r['detail']) . '</td><td class="mono">' . h($r['ip']) . '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="6" class="empty">No audit entries yet.</td></tr>';
echo '</tbody></table>' . pager($pg, max(1, (int)ceil($total / $per)), ['page' => 'audit']) . '</div>';
