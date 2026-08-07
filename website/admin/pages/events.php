<?php
/** Events & Feedback — paginated filtered event log + user-feedback inbox. */
declare(strict_types=1);
echo flash_html();
$tab = $_GET['tab'] ?? 'events';
echo '<div class="phead"><div><h1>Events &amp; Feedback</h1><p>Every action across all installs, plus messages from users</p></div>'
   . '<div class="chips"><a class="chip' . ($tab === 'events' ? ' on' : '') . '" href="?page=events&tab=events">Event log</a>'
   . '<a class="chip' . ($tab === 'feedback' ? ' on' : '') . '" href="?page=events&tab=feedback">Feedback</a></div></div>';

if ($tab === 'feedback') {
    $rows = qa('SELECT id,ts,version,contact,message,seen FROM feedback ORDER BY id DESC LIMIT 60');
    echo '<div class="card" style="margin-top:16px">';
    if ($rows) {
        foreach ($rows as $f) {
            echo '<div style="padding:14px 18px;border-bottom:1px solid var(--line2)">'
               . '<div style="display:flex;gap:10px;align-items:center;font-size:11.5px;color:var(--faint);margin-bottom:5px;flex-wrap:wrap">'
               . ($f['seen'] ? '' : '<span style="width:7px;height:7px;border-radius:50%;background:var(--brand);display:inline-block"></span> ')
               . h(gmdate('d M Y · H:i', (int)$f['ts'])) . ' · v' . h($f['version']) . ($f['contact'] ? ' · ' . h($f['contact']) : '')
               . '<span style="flex:1"></span>'
               . '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=events&tab=feedback"><input type="hidden" name="id" value="' . (int)$f['id'] . '">'
               . ($f['seen'] ? '' : '<button class="btn ghost" style="padding:3px 9px;font-size:11px;margin-right:6px" name="action" value="fbseen">Mark read</button>')
               . (can('controls') ? '<button class="btn ghost" style="padding:3px 9px;font-size:11px" name="action" value="fbdel" onclick="return confirm(\'Delete?\')">Delete</button>' : '')
               . '</form></div><div style="font-size:13.5px;line-height:1.5">' . nl2br(h($f['message'])) . '</div></div>';
        }
    } else echo empty_state('No feedback yet. It arrives when users tap “Send feedback” in the app.', 'msg');
    echo '</div>';
    return;
}

// Event log with filters + pagination
$f = ['q' => trim($_GET['q'] ?? ''), 'version' => trim($_GET['fv'] ?? ''), 'os' => trim($_GET['fo'] ?? '')];
$rangeKey = $_GET['r'] ?? 'all'; $rg = resolve_range($rangeKey); if ($rangeKey !== 'all') $f['since'] = $rg['since'];
$pg = max(1, (int)($_GET['pg'] ?? 1));
$res = events_page($f, $pg);

$vopts = '<option value="">All versions</option>'; foreach (distinct_versions() as $v) { if ($v === '') continue; $vopts .= '<option value="' . h($v) . '"' . ($v === $f['version'] ? ' selected' : '') . '>' . h($v) . '</option>'; }
$oopts = '<option value="">All OS</option>'; foreach (distinct_os() as $o) { if ($o === '') continue; $oopts .= '<option value="' . h($o) . '"' . ($o === $f['os'] ? ' selected' : '') . '>' . h($o) . '</option>'; }
$ropts = ''; foreach (ranges() as $k => $l) $ropts .= '<option value="' . $k . '"' . ($k === $rangeKey ? ' selected' : '') . '>' . h($l) . '</option>';

echo '<div class="card" style="margin-top:16px"><div class="pad" style="padding-bottom:8px"><form class="filters" method="get"><input type="hidden" name="page" value="events">'
   . '<input class="inp" name="q" value="' . h($f['q']) . '" placeholder="Search feature…" style="min-width:180px">'
   . '<select class="inp" name="fv">' . $vopts . '</select><select class="inp" name="fo">' . $oopts . '</select><select class="inp" name="r">' . $ropts . '</select>'
   . '<button class="btn">' . icon('search') . 'Filter</button>'
   . '<a class="btn ghost" href="admin.php?do=csv">' . icon('download') . 'CSV</a></form></div>'
   . '<table class="tbl"><thead><tr><th>When (UTC)</th><th>Feature</th><th>Version</th><th>OS</th><th>Install</th></tr></thead><tbody>';
foreach ($res['rows'] as $r) {
    echo '<tr><td class="mut">' . h(gmdate('d M Y · H:i', (int)$r['ts'])) . '</td><td><b>' . h($r['event']) . '</b></td>'
       . '<td class="mut">' . h($r['version']) . '</td><td class="mut">' . h($r['os']) . '</td>'
       . '<td><a class="mono" href="?page=install&install=' . h($r['install']) . '">' . h(substr($r['install'], 0, 8)) . '</a></td></tr>';
}
if (!$res['rows']) echo '<tr><td colspan="5" class="empty">No matching events.</td></tr>';
echo '</tbody></table>' . pager($res['page'], $res['pages'], ['page' => 'events', 'q' => $f['q'], 'fv' => $f['version'], 'fo' => $f['os'], 'r' => $rangeKey]) . '</div>';
echo '<p class="faint" style="font-size:12px;margin-top:10px">' . nf($res['total']) . ' total events match.</p>';
