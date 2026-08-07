<?php
/** Dashboard — Overview. Premium KPI cards + interactive charts. */
declare(strict_types=1);
$rg = resolve_range($_GET['r'] ?? '30');
$m = metrics_overview($rg);
$span = min($rg['days'], 30);

// range chips
$chips = '<div class="chips">';
foreach (ranges() as $k => $l) $chips .= '<a class="chip' . ($k === $rg['key'] ? ' on' : '') . '" href="?page=dashboard&r=' . $k . '">' . h($l) . '</a>';
$chips .= '</div>';

echo '<div class="phead"><div><h1>Overview</h1><p>Anonymous usage across all ApneScan installs · ' . h($rg['label']) . '</p></div>' . $chips . '</div>';

// KPI grid
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('users', nf($m['installs']), 'Total installs')
   . kpi('activity', nf($m['online']), 'Online now')
   . kpi('user', nf($m['active_today']), "Today's users")
   . kpi('user', nf($m['active_7']), 'Weekly users')
   . kpi('user', nf($m['active_30']), 'Monthly users')
   . kpi('trend', nf($m['new']), 'New users', delta_badge($m['new_delta']))
   . kpi('repeat', $m['retention'] . '%', 'Returning users')
   . kpi('pulse', nf($m['sessions']), 'Active sessions')
   . kpi('scan', nf($m['scans_today']), "Today's scans")
   . kpi('text', nf($m['ocr_today']), "Today's OCR")
   . kpi('save', nf($m['pdf_today']), "Today's PDFs")
   . kpi('layers', nf($m['events']), 'Events', delta_badge($m['events_delta']))
   . kpi('trend', $m['avg_user'], 'Avg events / user')
   . kpi('clock', human_ms($m['avg_scan_ms']), 'Avg scan time')
   . kpi('layers', $m['avg_pages'], 'Avg pages / scan')
   . kpi('save', $m['avg_pdf_kb'] > 0 ? human_bytes($m['avg_pdf_kb'] * 1024) : '—', 'Avg PDF size')
   . kpi('text', human_ms($m['avg_ocr_ms']), 'Avg OCR time')
   . kpi('alert', $m['crash_rate'] . '%', 'Crash rate')
   . kpi('globe', nf($m['countries']), 'Countries')
   . kpi('db', human_bytes($m['db_bytes']), 'Database size')
   . '</div>';

// Charts row
echo '<div class="sec">' . icon('chart') . 'Activity &amp; growth</div>';
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('pulse') . 'Daily activity</div><div class="csub">Events &amp; active users · last ' . $span . ' days</div>'
   . chartjs('cDaily', ['type' => 'line', 'data' => [
        'labels' => array_column(daily_series($span), 'label'),
        'datasets' => [
            ['label' => 'Events', 'data' => array_column(daily_series($span), 'v'), 'borderColor' => '#8b5cf6', 'backgroundColor' => '#8b5cf6', 'fill' => true, 'tension' => .35, 'pointRadius' => 0, 'borderWidth' => 2.5],
            ['label' => 'Active users', 'data' => array_column(daily_users($span), 'v'), 'borderColor' => '#16a34a', 'backgroundColor' => '#16a34a', 'fill' => false, 'tension' => .35, 'pointRadius' => 0, 'borderWidth' => 2],
        ]], 'options' => ['as_area' => true]]) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('trend') . 'Install growth</div><div class="csub">Cumulative installs over time</div>'
   . chartjs('cGrowth', line_config(growth_series(), 'Installs', '#6d28d9')) . '</div>'
   . '</div>';

// Feature usage + funnel
echo '<div class="sec">' . icon('tag') . 'Features &amp; conversion</div>';
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('tag') . 'Feature usage</div><div class="csub">% = share of all installs</div>' . barlist(feature_usage($rg['since'], 12), 'event', 'c', $m['installs'], 'u') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('funnel') . 'Adoption funnel</div><div class="csub">Install → scan → save → share</div>' . funnel_html(funnel()) . '</div>'
   . '</div>';

// Versions + OS
echo '<div class="sec">' . icon('layers') . 'Versions &amp; platforms</div>';
$va = version_active(); $latest = latest_version();
$os = os_dist();
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('layers') . 'Version adoption</div><div class="csub">' . ($latest ? 'Latest is <b>' . h($latest) . '</b>' : 'Active installs per version') . '</div>'
   . chartjs('cVer', doughnut_config(array_map(fn($r) => $r['version'] ?: '—', $va), array_map(fn($r) => (int)$r['u'], $va)), 'sm') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('monitor') . 'Operating systems</div><div class="csub">Windows build in use</div>' . barlist($os, 'os', 'u') . '</div>'
   . '</div>';
