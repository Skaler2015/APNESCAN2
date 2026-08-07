<?php
/** OCR Analytics — usage now; accuracy/timing/language in Phase 2. */
declare(strict_types=1);
$o = ocr_totals();
$ocrTime = event_stats('ocr_ms');
$ok = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='ocr_ok'");
$fail = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='ocr_fail'");
$successRate = ($ok + $fail) > 0 ? round($ok / ($ok + $fail) * 100) : 0;
$langs = prefixed_events('ocr_lang_');
$langLabel = ['eng' => 'English', 'hin' => 'Hindi'];
$langRows = array_map(fn($r) => ['name' => $langLabel[$r['name']] ?? strtoupper($r['name']), 'c' => $r['c']], $langs);

echo '<div class="phead"><div><h1>' . h(t('OCR Analytics')) . '</h1><p>' . h(t('sub_ocr')) . '</p></div></div>';
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('text', nf($o['runs']), 'OCR actions')
   . kpi('users', nf($o['onusers']), 'Installs using OCR')
   . kpi('clock', human_ms($ocrTime['avg']), 'Avg OCR time')
   . kpi('check', $successRate . '%', 'Success rate')
   . kpi('eye', nf($ok), 'Successful reads')
   . kpi('alert', nf($fail), 'Blank / failed')
   . '</div>';
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('text') . 'Languages used</div><div class="csub">Recognition language</div>' . ($langRows ? barlist($langRows, 'name', 'c') : empty_state('Language data appears as clients update to 1.0.73+.', 'text')) . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('check') . 'Success vs blank</div><div class="csub">Pages where text was found</div>' . (($ok + $fail) > 0 ? chartjs('ocrPie', doughnut_config(['Text found', 'Blank/failed'], [$ok, $fail]), 'sm') : empty_state('Success data appears as clients update.', 'check')) . '</div>'
   . '</div>';
