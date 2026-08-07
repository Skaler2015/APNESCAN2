<?php
/** Operating Systems — Windows build distribution. */
declare(strict_types=1);
$os = os_dist();
echo '<div class="phead"><div><h1>Operating Systems</h1><p>Windows builds ApneScan runs on</p></div></div>';
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('monitor') . 'OS distribution</div><div class="csub">Unique installs per Windows build</div>'
   . ($os ? chartjs('osChart', doughnut_config(array_map(fn($r) => $r['os'] ?: '—', $os), array_map(fn($r) => (int)$r['u'], $os)), 'sm') : empty_state('No OS data yet.')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('layers') . 'Breakdown</div>' . barlist($os, 'os', 'u') . '</div>'
   . '</div>';
