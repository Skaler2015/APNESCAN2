<?php
/** System Health — database size, table breakdown, error rate, environment. */
declare(strict_types=1);
global $DB_NAME;
$now = time();
$dbBytes = db_size_bytes();
$ev24 = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$now - 86400]);
$cr24 = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='crash' AND ts>=?", [$now - 86400]);
$errRate = $ev24 > 0 ? round($cr24 / $ev24 * 100, 2) : 0;
$rowsTotal = (int) q1('SELECT COUNT(*) FROM events');
$oldest = (int) q1('SELECT MIN(ts) FROM events');
$tables = [];
try {
    foreach (qa('SELECT table_name n, (data_length+index_length) b, table_rows r FROM information_schema.tables WHERE table_schema=? ORDER BY b DESC', [$DB_NAME]) as $t)
        $tables[] = $t;
} catch (Throwable $e) {}

echo '<div class="phead"><div><h1>System Health</h1><p>Database, performance and environment status</p></div></div>';
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('db', human_bytes($dbBytes), 'Database size')
   . kpi('layers', nf($rowsTotal), 'Event rows')
   . kpi('alert', $errRate . '%', 'Error rate (24h)')
   . kpi('clock', $oldest ? floor(($now - $oldest) / 86400) . 'd' : '—', 'Data span')
   . kpi('check', 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'Runtime')
   . kpi('shield', $errRate < 1 ? 'Healthy' : ($errRate < 5 ? 'Watch' : 'Alert'), 'Status')
   . '</div>';

echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('db') . 'Tables</div><div class="csub">Size &amp; row count</div></div>'
   . '<table class="tbl"><thead><tr><th>Table</th><th class="num">Rows</th><th class="num">Size</th></tr></thead><tbody>';
foreach ($tables as $t) echo '<tr><td class="mono">' . h($t['n']) . '</td><td class="num">' . nf($t['r']) . '</td><td class="num">' . human_bytes($t['b']) . '</td></tr>';
if (!$tables) echo '<tr><td colspan="3" class="empty">Table stats unavailable on this host.</td></tr>';
echo '</tbody></table></div>';

echo '<div class="card pad"><div class="ctitle">' . icon('db') . 'Data maintenance</div><div class="csub">Archive old events to keep queries fast</div>';
if (can('settings')) {
    echo '<form method="post" style="margin-top:14px">' . csrf_field() . '<input type="hidden" name="back" value="admin.php?page=health">'
       . '<label class="fl">Delete events older than (days)</label><input class="inp" name="days" type="number" value="365" min="30" style="max-width:160px">'
       . '<div style="margin-top:14px"><button class="btn ghost" name="action" value="archive" onclick="return confirm(\'Permanently delete old events?\')">' . icon('trash') . 'Archive now</button></div></form>';
} else echo '<p class="mut" style="font-size:13px;margin-top:12px">Only admins can archive data.</p>';
echo '</div></div>';
