<?php
/**
 * ApneScan Admin — reusable UI render helpers. Pure presentation: icons, KPI
 * cards, bar lists, Chart.js canvases, tables, pagination, empty states.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

function icon(string $n): string {
    $p = [
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
        'chart'=>'<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>','activity'=>'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'users'=>'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
        'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/>','scan'=>'<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M3 12h18"/>',
        'text'=>'<path d="M4 7V4h16v3M9 20h6M12 4v16"/>','tag'=>'<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.4"/>',
        'report'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
        'layers'=>'<path d="M12 2l10 6-10 6L2 8z"/><path d="M2 12l10 6 10-6"/>','monitor'=>'<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>','bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'save'=>'<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'download'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>','settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 0 1-4 0v-.1A1.6 1.6 0 0 0 6.5 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 4.6 15H4.5a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.4-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 11 4.6V4.5a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.4l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1A1.6 1.6 0 0 0 19.4 11h.1a2 2 0 0 1 0 4z"/>',
        'help'=>'<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01"/>','heart'=>'<path d="M12 21s-8-4.5-8-11a4.5 4.5 0 0 1 8-3 4.5 4.5 0 0 1 8 3c0 6.5-8 11-8 11z"/>',
        'pulse'=>'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>','globe'=>'<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
        'clock'=>'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>','crown'=>'<path d="M2 20h20M4 6l4 6 4-8 4 8 4-6v10H4z"/>','funnel'=>'<path d="M22 3H2l8 9.5V19l4 2v-8.5z"/>',
        'repeat'=>'<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'trend'=>'<path d="M23 6l-9.5 9.5-5-5L1 18"/><path d="M17 6h6v6"/>','alert'=>'<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h16.9a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'msg'=>'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>','db'=>'<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5M3 12a9 3 0 0 0 18 0"/>',
        'search'=>'<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/>','refresh'=>'<path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/>',
        'moon'=>'<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/>','logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'menu'=>'<path d="M3 12h18M3 6h18M3 18h18"/>','key'=>'<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.5 12.5 20 3l1 4-3 1 1 3"/>','role'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11l-3 3-2-2"/>',
        'backup'=>'<path d="M21 12a9 9 0 1 1-9-9c2.5 0 4.8 1 6.4 2.6M21 4v5h-5"/>','flag'=>'<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
        'zap'=>'<path d="M13 2 3 14h9l-1 8 10-12h-9z"/>','printer'=>'<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/>',
        'plus'=>'<path d="M12 5v14M5 12h14"/>','check'=>'<path d="M20 6 9 17l-5-5"/>','x'=>'<path d="M18 6 6 18M6 6l12 12"/>','trash'=>'<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/>',
        'chevron'=>'<path d="M9 18l6-6-6-6"/>','eye'=>'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24">' . ($p[$n] ?? '') . '</svg>';
}

function kpi(string $ic, $n, string $label, string $badge = ''): string {
    return '<div class="kpi"><div class="kr"><span class="bo">' . icon($ic) . '</span>' . $badge . '</div>'
         . '<div class="n">' . $n . '</div><div class="l">' . h($label) . '</div></div>';
}
function delta_badge(int $d): string {
    $c = $d > 0 ? 'up' : ($d < 0 ? 'down' : 'flat'); $ar = $d > 0 ? '▲ ' : ($d < 0 ? '▼ ' : '');
    return '<span class="delta ' . $c . '">' . $ar . abs($d) . '%</span>';
}
function barlist(array $rows, string $nk, string $vk, int $total = 0, ?string $ak = null, string $prefix = ''): string {
    if (!$rows) return empty_state(t('c_nodata'));
    $max = 0; foreach ($rows as $r) $max = max($max, (int)$r[$vk]);
    $o = '<div class="blist">';
    foreach ($rows as $r) {
        $v = (int)$r[$vk]; $w = $max > 0 ? max(3, round($v / $max * 100)) : 0;
        $name = ($r[$nk] === '' || $r[$nk] === null) ? '(unknown)' : $r[$nk];
        $ad = ($ak !== null && $total > 0) ? ' <span class="tag">' . pct((int)$r[$ak], $total) . '%</span>' : '';
        $o .= '<div class="brow"><div class="bname" title="' . h($name) . '">' . $prefix . h($name) . $ad . '</div>'
            . '<div class="btrack"><div class="bfill" style="width:' . $w . '%"></div></div>'
            . '<div class="bval">' . nf($v) . '</div></div>';
    }
    return $o . '</div>';
}
function empty_state(string $text, string $ic = 'search'): string {
    return '<div class="empty">' . icon($ic) . '<div>' . h($text) . '</div></div>';
}
function heatmap(array $hours): string {
    $max = max(1, max($hours)); $o = '<div class="heat">';
    foreach ($hours as $hr => $c) { $op = $c > 0 ? max(0.14, $c / $max) : 0.05; $o .= '<div class="hc" title="' . $hr . ':00 UTC — ' . nf($c) . '" style="background:color-mix(in srgb,var(--brand) ' . round($op * 100) . '%,transparent)"></div>'; }
    return $o . '</div><div class="hlabels"><span>0</span><span style="grid-column:7">6</span><span style="grid-column:13">12</span><span style="grid-column:19">18</span><span style="grid-column:24">23</span></div>';
}
function funnel_html(array $steps): string {
    $max = 1; foreach ($steps as $s) $max = max($max, (int)$s[1]);
    $inst = (int)($steps[0][1] ?? 0); $o = '<div class="blist">';
    foreach ($steps as $s) {
        $w = max(6, round((int)$s[1] / $max * 100)); $p = pct((int)$s[1], $inst);
        $o .= '<div style="display:grid;grid-template-columns:100px 1fr auto;gap:12px;align-items:center">'
            . '<div class="bname">' . h($s[0]) . '</div>'
            . '<div style="height:32px;border-radius:9px;background:linear-gradient(90deg,var(--brand),var(--accent));display:flex;align-items:center;padding:0 12px;color:#fff;font-weight:700;font-size:13px;width:' . $w . '%;min-width:42px">' . nf($s[1]) . '</div>'
            . '<div class="bval">' . $p . '%</div></div>';
    }
    return $o . '</div>';
}
/** Chart.js canvas + init with a hover toolbar (download PNG, reset zoom). */
function chartjs(string $id, array $config, string $cls = ''): string {
    $json = json_encode($config, JSON_UNESCAPED_SLASHES);
    $tools = '<div class="charttools">'
           . '<button type="button" class="ctool" title="Reset zoom" onclick="ASchartReset(\'' . h($id) . '\')">' . icon('refresh') . '</button>'
           . '<button type="button" class="ctool" title="Download PNG" onclick="ASchartDL(\'' . h($id) . '\')">' . icon('download') . '</button></div>';
    return '<div class="chartbox ' . $cls . '">' . $tools . '<canvas id="' . h($id) . '"></canvas></div>'
         . '<script>ASchart(' . json_encode($id) . ',' . $json . ');</script>';
}
/** Line/area chart config from a [{label,v}] series. */
function line_config(array $series, string $label, string $hex, bool $area = true): array {
    return ['type' => 'line', 'data' => ['labels' => array_column($series, 'label'),
        'datasets' => [['label' => $label, 'data' => array_column($series, 'v'), 'borderColor' => $hex,
            'backgroundColor' => $hex, 'fill' => $area, 'tension' => 0.35, 'pointRadius' => 0, 'borderWidth' => 2.5]]],
        'options' => ['as_area' => $area]];
}
function bar_config(array $labels, array $data, string $label, string $hex): array {
    return ['type' => 'bar', 'data' => ['labels' => $labels,
        'datasets' => [['label' => $label, 'data' => $data, 'backgroundColor' => $hex, 'borderRadius' => 6, 'maxBarThickness' => 26]]]];
}
function doughnut_config(array $labels, array $data): array {
    $col = ['#8b5cf6', '#6d28d9', '#a78bfa', '#c084fc', '#7c3aed', '#9333ea', '#5b21b6', '#d8b4fe'];
    return ['type' => 'doughnut', 'data' => ['labels' => $labels,
        'datasets' => [['data' => $data, 'backgroundColor' => array_slice(array_pad($col, count($data), '#8b5cf6'), 0, count($data)), 'borderWidth' => 0]]],
        'options' => ['cutout' => '64%']];
}
function pager(int $page, int $pages, array $params): string {
    if ($pages <= 1) return '';
    $mk = function ($p) use ($params) { $params['pg'] = $p; return '?' . http_build_query($params); };
    $o = '<div class="pager"><span class="mut" style="border:0;margin-right:auto">Page ' . $page . ' of ' . $pages . '</span>';
    if ($page > 1) $o .= '<a href="' . h($mk($page - 1)) . '">‹ Prev</a>';
    $start = max(1, $page - 2); $end = min($pages, $start + 4);
    for ($i = $start; $i <= $end; $i++) $o .= $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . h($mk($i)) . '">' . $i . '</a>';
    if ($page < $pages) $o .= '<a href="' . h($mk($page + 1)) . '">Next ›</a>';
    return $o . '</div>';
}
