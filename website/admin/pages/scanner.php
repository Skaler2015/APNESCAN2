<?php
/** Scanner Analytics — paper source, pages, and (Phase 2) driver/model/speed. */
declare(strict_types=1);
$src = scanner_sources();
$pages = pages_scanned_total();
$scanUsers = (int) q1("SELECT COUNT(DISTINCT install) FROM events WHERE event='scan'");
$scanTotal = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='scan'");
$avgPages = $scanTotal > 0 ? round($pages / max(1, $scanTotal), 1) : 0;
$scanners = device_field('scanner');

echo '<div class="phead"><div><h1>Scanner Analytics</h1><p>How documents are being captured</p></div></div>';
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

echo '<div class="sec">' . icon('zap') . 'Performance &amp; settings</div>';
echo '<div class="grid g3">'
   . '<div class="card pad"><div class="ctitle">' . icon('clock') . 'Average scan time</div>' . empty_state('Timing arrives in Phase 2.', 'clock') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('layers') . 'DPI &amp; color mode</div>' . empty_state('DPI/color capture in Phase 2.', 'layers') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('alert') . 'Scanner errors</div>' . empty_state('No scanner errors reported.', 'check') . '</div>'
   . '</div>';
