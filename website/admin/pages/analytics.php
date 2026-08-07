<?php
/** Analytics — deep dive: activity, retention, features, timing, geography. */
declare(strict_types=1);
$rg = resolve_range($_GET['r'] ?? '30');
$span = min($rg['days'], 30);
$chips = '<div class="chips">';
foreach (ranges() as $k => $l) $chips .= '<a class="chip' . ($k === $rg['key'] ? ' on' : '') . '" href="?page=analytics&r=' . $k . '">' . h($l) . '</a>';
$chips .= '</div>';
echo '<div class="phead"><div><h1>Analytics</h1><p>Interactive trends, retention and geography · ' . h($rg['label']) . '</p></div>' . $chips . '</div>';

echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('pulse') . 'Daily events</div><div class="csub">Last ' . $span . ' days</div>' . chartjs('aEvt', line_config(daily_series($span), 'Events', '#8b5cf6')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('users') . 'Daily active users</div><div class="csub">Distinct installs per day</div>' . chartjs('aUsers', line_config(daily_users($span), 'Users', '#16a34a')) . '</div>'
   . '</div>';

// weekly aggregate bars
$dser = daily_series(min($rg['days'], 84)); $weeks = [];
foreach ($dser as $i => $d) { $wk = intdiv(count($dser) - 1 - $i, 7); $weeks[$wk] = ($weeks[$wk] ?? 0) + $d['v']; }
krsort($weeks); $wlabels = []; $wvals = [];
$n = count($weeks); foreach ($weeks as $idx => $v) { $wlabels[] = 'Wk-' . $idx; $wvals[] = $v; }
$wlabels = array_reverse($wlabels); $wvals = array_reverse($wvals);
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('chart') . 'Weekly activity</div><div class="csub">Events grouped by week</div>' . chartjs('aWeek', bar_config($wlabels, $wvals, 'Events', '#6d28d9')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('trend') . 'Install growth</div><div class="csub">Cumulative</div>' . chartjs('aGrow', line_config(growth_series(), 'Installs', '#9333ea')) . '</div>'
   . '</div>';

// features + cohorts
echo '<div class="sec">' . icon('repeat') . 'Retention &amp; features</div>';
$coh = cohorts();
$cohHtml = '<table class="tbl"><thead><tr><th>Cohort week</th><th class="num">New</th><th class="num">Retained</th><th class="num">Rate</th></tr></thead><tbody>';
foreach ($coh as $c) { $rate = pct((int)$c['ret'], (int)$c['n']); $cohHtml .= '<tr><td>' . h(gmdate('d M Y', (int)$c['wkstart'])) . '</td><td class="num">' . nf($c['n']) . '</td><td class="num">' . nf($c['ret']) . '</td><td class="num"><span class="pill">' . $rate . '%</span></td></tr>'; }
if (!$coh) $cohHtml .= '<tr><td colspan="4" class="empty">No cohort data yet.</td></tr>';
$cohHtml .= '</tbody></table>';
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('tag') . 'Feature usage</div><div class="csub">% = share of all installs</div>' . barlist(feature_usage($rg['since'], 14), 'event', 'c', metrics_overview($rg)['installs'], 'u') . '</div>'
   . '<div class="card"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('repeat') . 'Weekly retention cohorts</div><div class="csub">% still active 7+ days after first install</div></div>' . $cohHtml . '</div>'
   . '</div>';

// hours + country
echo '<div class="sec">' . icon('globe') . 'Timing &amp; geography</div>';
$cc = country_dist(); $ccRows = array_map(fn($r) => ['name' => flag($r['country']) . ' ' . $r['country'], 'u' => $r['u']], $cc);
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('clock') . 'Active hours (UTC)</div><div class="csub">Events by hour of day</div>' . heatmap(hours_dist($rg['since'])) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('globe') . 'Countries</div><div class="csub">Coarse location from IP</div>' . ($ccRows ? barlist($ccRows, 'name', 'u') : empty_state('Country data appears as clients update.', 'globe')) . '</div>'
   . '</div>';
