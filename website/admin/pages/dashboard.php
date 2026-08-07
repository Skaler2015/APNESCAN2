<?php
/** Dashboard — Overview. Premium KPI cards + interactive charts + insights. */
declare(strict_types=1);
$rg = resolve_range($_GET['r'] ?? '30');
$m = metrics_overview($rg);
$span = min($rg['days'], 30);

// range chips (translated labels)
$chips = '<div class="chips">';
foreach (ranges() as $k => $l) $chips .= '<a class="chip' . ($k === $rg['key'] ? ' on' : '') . '" href="?page=dashboard&r=' . $k . '">' . h(t('range_' . $k)) . '</a>';
$chips .= '</div>';

echo '<div class="phead"><div><h1>' . h(t('Overview')) . '</h1><p>' . h(t('ov_sub')) . ' · ' . h(t('range_' . $rg['key'])) . '</p></div>' . $chips . '</div>';

// Smart Insights
$ins = insights();
if ($ins) {
    $tmap = ['good' => 'g', 'warn' => 'w', 'bad' => 'r', 'info' => ''];
    echo '<div class="sec" style="margin-top:20px">' . icon('zap') . h(t('smart_insights')) . '</div><div class="grid g3">';
    foreach ($ins as $x) {
        echo '<div class="card pad" style="display:flex;gap:12px;align-items:flex-start">'
           . '<span class="pill ' . ($tmap[$x['tone']] ?? '') . '" style="width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:none">' . icon($x['icon']) . '</span>'
           . '<span style="font-size:13px;line-height:1.5;font-weight:500">' . h($x['text']) . '</span></div>';
    }
    echo '</div>';
}

// KPI grid
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('users', nf($m['installs']), t('k_total_installs'))
   . kpi('activity', nf($m['online']), t('k_online_now'))
   . kpi('user', nf($m['active_today']), t('k_today_users'))
   . kpi('user', nf($m['active_7']), t('k_weekly_users'))
   . kpi('user', nf($m['active_30']), t('k_monthly_users'))
   . kpi('trend', nf($m['new']), t('k_new_users'), delta_badge($m['new_delta']))
   . kpi('repeat', $m['retention'] . '%', t('k_returning'))
   . kpi('pulse', nf($m['sessions']), t('k_sessions'))
   . kpi('scan', nf($m['scans_today']), t('k_today_scans'))
   . kpi('text', nf($m['ocr_today']), t('k_today_ocr'))
   . kpi('save', nf($m['pdf_today']), t('k_today_pdf'))
   . kpi('layers', nf($m['events']), t('k_events'), delta_badge($m['events_delta']))
   . kpi('trend', $m['avg_user'], t('k_avg_events'))
   . kpi('clock', human_ms($m['avg_scan_ms']), t('k_avg_scan'))
   . kpi('layers', $m['avg_pages'], t('k_avg_pages'))
   . kpi('save', $m['avg_pdf_kb'] > 0 ? human_bytes($m['avg_pdf_kb'] * 1024) : '—', t('k_avg_pdf'))
   . kpi('text', human_ms($m['avg_ocr_ms']), t('k_avg_ocr'))
   . kpi('alert', $m['crash_rate'] . '%', t('k_crash_rate'))
   . kpi('globe', nf($m['countries']), t('k_countries'))
   . kpi('db', human_bytes($m['db_bytes']), t('k_db_size'))
   . '</div>';

// Charts row
echo '<div class="sec">' . icon('chart') . h(t('activity_growth')) . '</div>';
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('pulse') . h(t('daily_activity')) . '</div><div class="csub">Events &amp; active users · last ' . $span . ' days</div>'
   . chartjs('cDaily', ['type' => 'line', 'data' => [
        'labels' => array_column(daily_series($span), 'label'),
        'datasets' => [
            ['label' => 'Events', 'data' => array_column(daily_series($span), 'v'), 'borderColor' => '#8b5cf6', 'backgroundColor' => '#8b5cf6', 'fill' => true, 'tension' => .35, 'pointRadius' => 0, 'borderWidth' => 2.5],
            ['label' => 'Active users', 'data' => array_column(daily_users($span), 'v'), 'borderColor' => '#16a34a', 'backgroundColor' => '#16a34a', 'fill' => false, 'tension' => .35, 'pointRadius' => 0, 'borderWidth' => 2],
        ]], 'options' => ['as_area' => true]]) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('trend') . h(t('install_growth')) . '</div><div class="csub">Cumulative installs over time</div>'
   . chartjs('cGrowth', line_config(growth_series(), 'Installs', '#6d28d9')) . '</div>'
   . '</div>';

// Feature usage + funnel
echo '<div class="sec">' . icon('tag') . h(t('features_conversion')) . '</div>';
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('tag') . h(t('feature_usage')) . '</div><div class="csub">% = share of all installs</div>' . barlist(feature_usage($rg['since'], 12), 'event', 'c', $m['installs'], 'u') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('funnel') . h(t('adoption_funnel')) . '</div><div class="csub">Install → scan → save → share</div>' . funnel_html(funnel()) . '</div>'
   . '</div>';

// Versions + OS
echo '<div class="sec">' . icon('layers') . h(t('versions_platforms')) . '</div>';
$va = version_active(); $latest = latest_version();
$os = os_dist();
echo '<div class="grid g2">'
   . '<div class="card pad"><div class="ctitle">' . icon('layers') . h(t('version_adoption')) . '</div><div class="csub">' . ($latest ? 'Latest is <b>' . h($latest) . '</b>' : 'Active installs per version') . '</div>'
   . chartjs('cVer', doughnut_config(array_map(fn($r) => $r['version'] ?: '—', $va), array_map(fn($r) => (int)$r['u'], $va)), 'sm') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('monitor') . h(t('operating_systems')) . '</div><div class="csub">Windows build in use</div>' . barlist($os, 'os', 'u') . '</div>'
   . '</div>';
