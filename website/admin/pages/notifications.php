<?php
/** Notification Center — derived alerts about the fleet + system. */
declare(strict_types=1);
$ns = notifications();
echo '<div class="phead"><div><h1>' . h(t('Notifications')) . '</h1><p>' . h(t('sub_notifications')) . '</p></div></div>';
echo '<div class="grid" style="margin-top:16px;gap:12px">';
$cmap = ['bad' => 'r', 'warn' => 'w', 'good' => 'g', 'info' => ''];
foreach ($ns as $x) {
    $pc = $cmap[$x['sev']] ?? '';
    echo '<a href="' . h($x['link']) . '" class="card pad" style="display:flex;align-items:center;gap:14px;color:inherit">'
       . '<span class="pill ' . $pc . '" style="width:40px;height:40px;border-radius:11px;display:grid;place-items:center">' . icon($x['sev'] === 'good' ? 'check' : 'alert') . '</span>'
       . '<span><b style="font-size:14px">' . h($x['title']) . '</b><br><span class="mut" style="font-size:12.5px">' . h($x['sub']) . '</span></span>'
       . '<span style="margin-left:auto;color:var(--faint)">' . icon('chevron') . '</span></a>';
}
echo '</div>';
