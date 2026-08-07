<?php
/** Admins & Roles — manage dashboard users (super_admin only for writes). */
declare(strict_types=1);
echo flash_html();
$rows = qa('SELECT username,role,created,last_login FROM admin_users ORDER BY created DESC');
echo '<div class="phead"><div><h1>' . h(t('Admins & Roles')) . '</h1><p>' . h(t('sub_users')) . '</p></div></div>';

echo '<div class="card" style="margin-top:16px"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('role') . 'Admin users</div><div class="csub">The bootstrap “admin” (super admin) always has full access</div></div>'
   . '<table class="tbl"><thead><tr><th>Username</th><th>Role</th><th>Created</th><th>Last login</th><th></th></tr></thead><tbody>';
echo '<tr><td><b>admin</b></td><td><span class="pill">Super Admin</span></td><td class="mut">bootstrap</td><td class="mut">—</td><td></td></tr>';
foreach ($rows as $r) {
    echo '<tr><td><b>' . h($r['username']) . '</b></td><td><span class="pill">' . h($GLOBALS['ROLE_LABELS'][$r['role']] ?? $r['role']) . '</span></td>'
       . '<td class="mut">' . ($r['created'] ? h(dt((int)$r['created'], 'd M Y')) : '—') . '</td>'
       . '<td class="mut">' . ($r['last_login'] ? ago($r['last_login']) : 'never') . '</td>'
       . '<td>' . (can('users') ? '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=users"><input type="hidden" name="username" value="' . h($r['username']) . '"><button class="btn ghost" style="padding:3px 9px;font-size:11px" name="action" value="user_del" onclick="return confirm(\'Remove this admin?\')">Remove</button></form>' : '') . '</td></tr>';
}
if (!$rows) echo '<tr><td colspan="5" class="empty">No extra admins yet — add one below.</td></tr>';
echo '</tbody></table></div>';

if (can('users')) {
    $ropts = ''; foreach ($GLOBALS['ROLE_LABELS'] as $k => $l) { if ($k === 'super_admin') continue; $ropts .= '<option value="' . $k . '">' . h($l) . '</option>'; }
    echo '<div class="sec">' . icon('plus') . 'Add / update an admin</div><div class="card pad" style="max-width:520px"><form method="post">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=users">'
       . '<label class="fl">Username</label><input class="inp" name="username" placeholder="e.g. manager1">'
       . '<label class="fl">Password</label><input class="inp" type="password" name="password" placeholder="6+ characters">'
       . '<label class="fl">Role</label><select class="inp" name="role">' . $ropts . '</select>'
       . '<div style="margin-top:16px"><button class="btn" name="action" value="user_add">Save admin</button></div></form>'
       . '<div class="csub" style="margin-top:16px"><b>Roles:</b> Admin (full ops), Manager (view + reports + export), Operator (view + app controls), Viewer (read-only).</div></div>';
}
