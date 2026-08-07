<?php
/** Export — download raw data in multiple formats. */
declare(strict_types=1);
echo '<div class="phead"><div><h1>' . h(t('Export')) . '</h1><p>' . h(t('sub_export')) . '</p></div></div>';
$fmts = [
    ['CSV', 'Spreadsheet-ready', 'csv'], ['JSON', 'For scripts & APIs', 'json'], ['XML', 'Structured markup', 'xml'],
];
echo '<div class="grid g3" style="margin-top:16px">';
foreach ($fmts as $f) {
    echo '<div class="card pad"><div class="ctitle">' . icon('download') . h($f[0]) . '</div><div class="csub">' . h($f[1]) . '</div>'
       . '<div style="margin-top:14px"><a class="btn" href="admin.php?do=' . $f[2] . '">' . icon('download') . 'Download ' . h($f[0]) . '</a></div></div>';
}
echo '</div>';
echo '<div class="sec">' . icon('printer') . 'Print</div><div class="card pad">'
   . '<p class="mut" style="font-size:13px;margin:0 0 12px">Open a print-friendly event list and use your browser\'s “Save as PDF”.</p>'
   . '<a class="btn ghost" href="admin.php?page=events" onclick="setTimeout(function(){window.print();},400)">' . icon('printer') . 'Print event log</a></div>';
