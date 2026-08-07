<?php
/** Feature Analytics — usage by category, most/least used, adoption. */
declare(strict_types=1);
$rg = resolve_range($_GET['r'] ?? '30');
$chips = '<div class="chips">';
foreach (ranges() as $k => $l) $chips .= '<a class="chip' . ($k === $rg['key'] ? ' on' : '') . '" href="?page=features&r=' . $k . '">' . h(t('range_' . $k)) . '</a>';
$chips .= '</div>';
echo '<div class="phead"><div><h1>' . h(t('Feature Analytics')) . '</h1><p>' . h(t('sub_features')) . ' · ' . h(t('range_' . $rg['key'])) . '</p></div>' . $chips . '</div>';

$use = feature_usage_map($rg['since']);
$adopt = feature_adoption_map($rg['since']);
$installs = metrics_overview($rg)['installs'];
$catalog = feature_catalog();

// Most / least used (from mapped, non-meta)
arsort($use);
$most = array_slice($use, 0, 1, true); $mostK = key($most); $mostV = current($most);
$leastCandidates = array_filter($use, fn($v) => $v > 0);
asort($leastCandidates); $leastK = key($leastCandidates); $leastV = current($leastCandidates);
$adopted = array_filter($adopt, fn($v) => $v > 0);
$avgAdopt = $adopted ? round(array_sum($adopted) / count($adopted) / max(1, $installs) * 100) : 0;

echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('crown', $mostK ? h($mostK) : '—', 'Most used feature')
   . kpi('trend', $mostK ? nf($mostV) . ' uses' : '—', 'Top feature volume')
   . kpi('eye', $leastK ? h($leastK) : '—', 'Least used feature')
   . kpi('repeat', $avgAdopt . '%', 'Avg feature adoption')
   . '</div>';

// Category totals -> doughnut + per-category bars
$catTotals = []; foreach ($catalog as $cat => $evs) { $t = 0; foreach ($evs as $e) $t += $use[$e] ?? 0; $catTotals[$cat] = $t; }
arsort($catTotals);
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('grid') . 'Usage by category</div><div class="csub">Where activity concentrates</div>'
   . (array_sum($catTotals) > 0 ? chartjs('featCat', doughnut_config(array_keys($catTotals), array_values($catTotals)), 'sm') : empty_state('No feature usage in range.')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('tag') . 'Top features</div><div class="csub">% = share of all installs</div>'
   . barlist(feature_usage($rg['since'], 12), 'event', 'c', $installs, 'u') . '</div>'
   . '</div>';

// Per-category breakdown
echo '<div class="sec">' . icon('layers') . h(t('s_by_category')) . '</div><div class="grid g3">';
foreach ($catalog as $cat => $evs) {
    $rows = [];
    foreach ($evs as $e) if (($use[$e] ?? 0) > 0) $rows[] = ['name' => $e, 'c' => $use[$e]];
    usort($rows, fn($a, $b) => $b['c'] <=> $a['c']);
    echo '<div class="card pad"><div class="ctitle">' . icon('tag') . h($cat) . '</div>' . ($rows ? barlist($rows, 'name', 'c') : empty_state('Not used yet.')) . '</div>';
}
echo '</div>';
