<?php
/** Version Analytics — distribution, adoption of latest, outdated installs. */
declare(strict_types=1);
$all = version_all(); $active = version_active(); $latest = latest_version();
$installs = (int) q1('SELECT COUNT(DISTINCT install) FROM events');
$onLatest = $latest !== '' ? (int) q1('SELECT COUNT(DISTINCT install) FROM (SELECT install,MAX(version) mv FROM events GROUP BY install) t WHERE mv=?', [$latest]) : 0;
$outdated = max(0, $installs - $onLatest);

echo '<div class="phead"><div><h1>' . h(t('Versions')) . '</h1><p>' . h(t('sub_versions')) . ' · latest is <b>' . h($latest ?: '—') . '</b></p></div></div>';
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('layers', h($latest ?: '—'), 'Latest version')
   . kpi('check', pct($onLatest, $installs) . '%', 'On latest')
   . kpi('users', nf($onLatest), 'Up-to-date installs')
   . kpi('alert', nf($outdated), 'Outdated installs')
   . '</div>';
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('layers') . 'Version distribution</div><div class="csub">Unique installs per version (all time)</div>'
   . chartjs('vDist', bar_config(array_map(fn($r) => $r['version'] ?: '—', $all), array_map(fn($r) => (int)$r['u'], $all), 'Installs', '#8b5cf6')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('activity') . 'Active adoption (7d)</div><div class="csub">Recently-active installs per version</div>' . barlist($active, 'version', 'u') . '</div>'
   . '</div>';
