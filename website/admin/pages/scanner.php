<?php
/** Scanner Analytics — paper source, pages, and (Phase 2) driver/model/speed. */
declare(strict_types=1);
$src = scanner_sources();
$pages = pages_scanned_total();
$scanUsers = (int) q1("SELECT COUNT(DISTINCT install) FROM events WHERE event='scan'");
$scanTotal = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='scan'");
$avgPages = $scanTotal > 0 ? round($pages / max(1, $scanTotal), 1) : 0;
$scanners = device_field('scanner');

echo '<div class="phead"><div><h1>' . h(t('Scanner Analytics')) . '</h1><p>' . h(t('sub_scanner')) . '</p></div></div>';
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('scan', nf($scanTotal), 'Scan operations')
   . kpi('layers', nf($pages), 'Pages scanned')
   . kpi('trend', $avgPages, 'Avg pages / scan')
   . kpi('users', nf($scanUsers), 'Installs that scanned')
   . '</div>';

echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('scan') . 'Paper source</div><div class="csub">Flatbed vs feeder vs auto</div>'
   . ($src ? chartjs('scSrc', doughnut_config(array_column($src, 'name'), array_map('intval', array_column($src, 'c'))), 'sm') : empty_state('Source data appears as clients update to 1.0.72+.', 'scan')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('monitor') . 'Scanner driver / model</div><div class="csub">Reported by the app</div>'
   . ($scanners ? barlist($scanners, 'v', 'u') : empty_state('Scanner model collection ships in Phase 2.', 'monitor')) . '</div>'
   . '</div>';

// Real capture settings (from 1.0.73+)
$dpi = prefixed_events('dpi_'); usort($dpi, fn($a, $b) => (int)$a['name'] <=> (int)$b['name']);
$dpiRows = array_map(fn($r) => ['name' => $r['name'] . ' dpi', 'c' => $r['c']], $dpi);
$colors = prefixed_events('color_');
$colorLabel = ['color' => 'Color', 'gray' => 'Grayscale', 'bw' => 'Black & White'];
$colorRows = array_map(fn($r) => ['name' => $colorLabel[$r['name']] ?? $r['name'], 'c' => $r['c']], $colors);
$scanTime = event_stats('scan_ms');

echo '<div class="sec">' . icon('zap') . h(t('s_perf')) . '</div>';
echo '<div class="grid kpis">'
   . kpi('clock', human_ms($scanTime['avg']), 'Average scan time')
   . kpi('layers', avg_pages_per_scan(), 'Avg pages / scan')
   . kpi('scan', nf($scanTime['count']), 'Timed scans')
   . kpi('alert', nf((int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='crash'")), 'Crashes (all)')
   . '</div>';
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('layers') . 'Resolution (DPI)</div><div class="csub">What resolutions people scan at</div>' . ($dpiRows ? barlist($dpiRows, 'name', 'c') : empty_state('DPI data appears as clients update to 1.0.73+.', 'layers')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('eye') . 'Color mode</div><div class="csub">Color vs grayscale vs B&amp;W</div>' . ($colorRows ? chartjs('scColor', doughnut_config(array_column($colorRows, 'name'), array_map('intval', array_column($colorRows, 'c'))), 'sm') : empty_state('Color-mode data appears as clients update.', 'eye')) . '</div>'
   . '</div>';
