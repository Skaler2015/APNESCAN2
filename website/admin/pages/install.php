<?php
/** Install detail — everything one anonymous install has done. */
declare(strict_types=1);
$inst = substr(preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($_GET['install'] ?? '')), 0, 40);
if ($inst === '') { echo empty_state('No install selected.', 'user'); return; }
$s = qr('SELECT MIN(ts) first, MAX(ts) last, SUM(cnt) events, COUNT(DISTINCT day) days, MAX(version) ver FROM events WHERE install=?', [$inst]) ?: [];
$cc = (string) q1('SELECT country FROM geo WHERE install=?', [$inst]);
$dev = qr('SELECT * FROM devices WHERE install=?', [$inst]);
$feat = qa('SELECT event, SUM(cnt) c FROM events WHERE install=? GROUP BY event ORDER BY c DESC LIMIT 25', [$inst]);
$hist = qa('SELECT ts,event,version FROM events WHERE install=? ORDER BY id DESC LIMIT 120', [$inst]);

echo '<a class="btn ghost" href="?page=events" style="margin-bottom:14px">' . icon('chevron') . ' Back to events</a>';
echo '<div class="phead"><div><h1>Install ' . h(substr($inst, 0, 12)) . ' ' . flag($cc) . '</h1><p class="mono">' . h($inst) . '</p></div></div>';
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('layers', nf($s['events'] ?? 0), 'Total events')
   . kpi('clock', ($s['days'] ?? 0), 'Active days')
   . kpi('user', !empty($s['first']) ? gmdate('d M Y', (int)$s['first']) : '—', 'First seen')
   . kpi('pulse', !empty($s['last']) ? gmdate('d M H:i', (int)$s['last']) : '—', 'Last seen')
   . kpi('tag', h($s['ver'] ?? '—'), 'Version')
   . kpi('globe', ($cc && $cc !== '??') ? flag($cc) . ' ' . h($cc) : '—', 'Country')
   . '</div>';

if ($dev) {
    echo '<div class="sec">' . icon('monitor') . 'Device</div><div class="card pad"><div class="grid g3">'
       . '<div><div class="faint" style="font-size:11px">CPU cores</div><b>' . h($dev['cpu_cores'] ?: '—') . '</b></div>'
       . '<div><div class="faint" style="font-size:11px">RAM</div><b>' . ($dev['ram_mb'] ? human_bytes($dev['ram_mb'] * 1048576) : '—') . '</b></div>'
       . '<div><div class="faint" style="font-size:11px">Architecture</div><b>' . h($dev['arch'] ?: '—') . '</b></div>'
       . '<div><div class="faint" style="font-size:11px">Screen</div><b>' . h($dev['screen'] ?: '—') . '</b></div>'
       . '<div><div class="faint" style="font-size:11px">Language</div><b>' . h($dev['lang'] ?: '—') . '</b></div>'
       . '<div><div class="faint" style="font-size:11px">Scanner</div><b>' . h($dev['scanner'] ?: '—') . '</b></div>'
       . '</div></div>';
}

echo '<div class="grid g2" style="margin-top:8px">'
   . '<div class="card pad"><div class="ctitle">' . icon('tag') . 'Feature usage</div>' . barlist($feat, 'event', 'c') . '</div>'
   . '<div class="card"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('pulse') . 'Activity timeline</div><div class="csub">Last 120 actions</div></div>'
   . '<table class="tbl"><thead><tr><th>When (UTC)</th><th>Feature</th><th>Ver</th></tr></thead><tbody>';
foreach ($hist as $r) echo '<tr><td class="mut">' . h(gmdate('d M · H:i', (int)$r['ts'])) . '</td><td><b>' . h($r['event']) . '</b></td><td class="mut">' . h($r['version']) . '</td></tr>';
if (!$hist) echo '<tr><td colspan="3" class="empty">No activity.</td></tr>';
echo '</tbody></table></div></div>';
