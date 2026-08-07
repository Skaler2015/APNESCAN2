<?php
/** Device Analytics — hardware/environment profiles (populated in Phase 2). */
declare(strict_types=1);
$count = devices_count();
echo '<div class="phead"><div><h1>' . h(t('Devices')) . '</h1><p>' . h(t('sub_devices')) . ' · ' . nf($count) . ' profiles</p></div></div>';
if ($count === 0) {
    echo '<div class="card pad" style="margin-top:16px">' . empty_state('Device profiles are collected from ApneScan 1.0.73+ (Phase 2). This panel fills in automatically as users update — architecture, CPU cores, RAM, screen resolution, monitors, language, timezone and scanner driver.', 'monitor') . '</div>';
    return;
}
$fields = [
    ['arch', 'Architecture', 'monitor'], ['cpu_cores', 'CPU cores', 'zap'], ['ram_mb', 'RAM', 'db'],
    ['screen', 'Screen resolution', 'monitor'], ['monitors', 'Monitor count', 'grid'],
    ['lang', 'Language', 'globe'], ['tz', 'Timezone', 'clock'], ['scanner', 'Scanner driver', 'scan'],
];
echo '<div class="grid g2" style="margin-top:16px">';
foreach ($fields as $f) {
    $rows = device_field($f[0]);
    if ($f[0] === 'ram_mb') foreach ($rows as &$r) $r['v'] = human_bytes(((int)$r['v']) * 1048576); unset($r);
    echo '<div class="card pad"><div class="ctitle">' . icon($f[2]) . h($f[1]) . '</div>' . ($rows ? barlist($rows, 'v', 'u') : empty_state('No data yet.')) . '</div>';
}
echo '</div>';
