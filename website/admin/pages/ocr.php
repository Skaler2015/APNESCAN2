<?php
/** OCR Analytics — usage now; accuracy/timing/language in Phase 2. */
declare(strict_types=1);
$o = ocr_totals();
$ocrToggle = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='setOcr'");
$getText = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='getText'");
echo '<div class="phead"><div><h1>OCR Analytics</h1><p>Text-recognition usage across installs</p></div></div>';
echo '<div class="grid kpis" style="margin-top:16px">'
   . kpi('text', nf($o['runs']), 'OCR actions')
   . kpi('users', nf($o['onusers']), 'Installs using OCR')
   . kpi('eye', nf($getText), 'Text extractions')
   . kpi('check', nf($ocrToggle), 'OCR enabled (saves)')
   . '</div>';
echo '<div class="grid g2" style="margin-top:16px">'
   . '<div class="card pad"><div class="ctitle">' . icon('text') . 'Languages used</div><div class="csub">Hindi / English breakdown</div>' . empty_state('Language capture ships in Phase 2.', 'text') . '</div>'
   . '<div class="card pad"><div class="ctitle">' . icon('clock') . 'Recognition time &amp; accuracy</div><div class="csub">Average OCR time and confidence</div>' . empty_state('Timing &amp; accuracy ship in Phase 2.', 'clock') . '</div>'
   . '</div>';
