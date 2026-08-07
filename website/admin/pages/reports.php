<?php
/** Reports — generate/download period & topic reports, with a live preview. */
declare(strict_types=1);
$now = time();
$daily = fn($sec) => (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$now - $sec]);
echo '<div class="phead"><div><h1>Reports</h1><p>Download ready-made reports or preview key numbers</p></div></div>';

$cards = [
    ['Daily report', 'Last 24 hours', 'daily'], ['Weekly report', 'Last 7 days', 'weekly'],
    ['Monthly report', 'Last 30 days', 'monthly'], ['Yearly report', 'Last 365 days', 'yearly'],
    ['Scanner report', 'Capture sources & pages', 'scanner'], ['OCR report', 'Recognition usage', 'ocr'],
    ['Feature report', 'Every feature ranked', 'feature'], ['Crash report', 'Crashes & error rate', 'crash'],
];
echo '<div class="grid g3" style="margin-top:16px">';
foreach ($cards as $c) {
    echo '<div class="card pad"><div class="ctitle">' . icon('report') . h($c[0]) . '</div><div class="csub">' . h($c[1]) . '</div>'
       . '<div style="margin-top:14px;display:flex;gap:8px">'
       . '<a class="btn ghost" href="admin.php?do=report&type=' . $c[2] . '&fmt=csv">' . icon('download') . 'CSV</a>'
       . '<a class="btn ghost" href="admin.php?do=report&type=' . $c[2] . '&fmt=json">JSON</a></div></div>';
}
echo '</div>';

echo '<div class="sec">' . icon('chart') . 'Snapshot</div>';
echo '<div class="grid kpis">'
   . kpi('layers', nf($daily(86400)), 'Events · 24h')
   . kpi('layers', nf($daily(7 * 86400)), 'Events · 7d')
   . kpi('layers', nf($daily(30 * 86400)), 'Events · 30d')
   . kpi('alert', nf((int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='crash' AND ts>=?", [$now - 7 * 86400])), 'Crashes · 7d')
   . '</div>';
