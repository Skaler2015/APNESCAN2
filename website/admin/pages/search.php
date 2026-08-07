<?php
/** Global Search — cross-entity results (features, installs, versions, OS, feedback). */
declare(strict_types=1);
$q = trim($_GET['q'] ?? '');
echo '<div class="phead"><div><h1>Search</h1><p>' . ($q !== '' ? 'Results for “' . h($q) . '”' : 'Type a query in the top bar to search across everything') . '</p></div></div>';
if ($q === '') { echo '<div class="card pad" style="margin-top:16px">' . empty_state('Search events, features, versions, OS, installs and feedback.', 'search') . '</div>'; return; }

$r = global_search($q);
$total = count($r['features']) + count($r['installs']) + count($r['versions']) + count($r['os']) + count($r['feedback']);
if ($total === 0) { echo '<div class="card pad" style="margin-top:16px">' . empty_state('No matches for “' . h($q) . '”.', 'search') . '</div>'; return; }

echo '<div class="grid g2" style="margin-top:16px">';

// features
$fh = '<div class="card"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('tag') . 'Features (' . count($r['features']) . ')</div></div><table class="tbl"><tbody>';
foreach ($r['features'] as $f) $fh .= '<tr><td><b>' . h($f['event']) . '</b></td><td class="num">' . nf($f['c']) . ' uses</td><td class="num mut">' . nf($f['u']) . ' users</td></tr>';
if (!$r['features']) $fh .= '<tr><td class="empty">No feature matches.</td></tr>';
echo $fh . '</tbody></table></div>';

// installs
$ih = '<div class="card"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('user') . 'Installs (' . count($r['installs']) . ')</div></div><table class="tbl"><tbody>';
foreach ($r['installs'] as $i) $ih .= '<tr><td><a class="mono" href="?page=install&install=' . h($i['install']) . '">' . h(substr($i['install'], 0, 12)) . '</a></td><td><span class="pill">' . h($i['ver'] ?: '—') . '</span></td><td class="num mut">' . ago($i['last']) . '</td></tr>';
if (!$r['installs']) $ih .= '<tr><td class="empty">No install matches.</td></tr>';
echo $ih . '</tbody></table></div>';

echo '</div><div class="grid g3" style="margin-top:16px">';
// versions
$vh = '<div class="card pad"><div class="ctitle">' . icon('layers') . 'Versions</div>' . ($r['versions'] ? barlist($r['versions'], 'version', 'u') : empty_state('None')) . '</div>';
$oh = '<div class="card pad"><div class="ctitle">' . icon('monitor') . 'Operating systems</div>' . ($r['os'] ? barlist($r['os'], 'os', 'u') : empty_state('None')) . '</div>';
$feh = '<div class="card pad"><div class="ctitle">' . icon('msg') . 'Feedback</div>';
if ($r['feedback']) { foreach ($r['feedback'] as $f) $feh .= '<div style="padding:8px 0;border-bottom:1px solid var(--line2)"><div class="faint" style="font-size:11px">' . h(gmdate('d M Y', (int)$f['ts'])) . ' · v' . h($f['version']) . '</div><div style="font-size:12.5px">' . h(mb_strimwidth($f['message'], 0, 90, '…')) . '</div></div>'; }
else $feh .= empty_state('None');
$feh .= '</div>';
echo $vh . $oh . $feh . '</div>';
