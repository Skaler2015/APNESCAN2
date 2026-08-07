<?php
/**
 * ApneScan Admin — metrics layer. Every analytics query the pages need lives
 * here as a small reusable function, so page views stay declarative and there
 * is no duplicated SQL. All statements are prepared.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

/** Standard date ranges used across the dashboard. */
function ranges(): array {
    return ['1' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days', '365' => '1 year', 'all' => 'All time'];
}
/** Resolve a range key to [since, prevSince, days, label]. */
function resolve_range(?string $key): array {
    $r = ranges(); $key = isset($r[$key]) ? $key : '30';
    $days = $key === 'all' ? 3650 : (int)$key;
    $now = time();
    $since = $key === 'all' ? 0 : $now - $days * 86400;
    return ['key' => $key, 'since' => $since, 'prev' => $since - $days * 86400, 'days' => $days, 'label' => $r[$key]];
}
function delta_pct(int $cur, int $prev): int {
    return $prev > 0 ? (int)round(($cur - $prev) / $prev * 100) : ($cur > 0 ? 100 : 0);
}

/**
 * SQL predicate that excludes "measurement" events (where cnt is a value, not a
 * count) and their prefixes, so action-count aggregations stay meaningful.
 */
function meta_filter(): string {
    return " AND event NOT IN ('scan_ms','ocr_ms','pdf_kb','session_min','pages_scanned') "
         . "AND event NOT LIKE 'dpi\\_%' AND event NOT LIKE 'color\\_%' "
         . "AND event NOT LIKE 'src\\_%' AND event NOT LIKE 'ocr\\_lang\\_%' ";
}

/** Headline KPIs for the overview + live API. */
function metrics_overview(array $rg): array {
    $now = time(); $since = $rg['since']; $prev = $rg['prev'];
    $installs = (int) q1('SELECT COUNT(DISTINCT install) FROM events');
    $eventsRange = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?' . meta_filter(), [$since]);
    $eventsPrev  = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=? AND ts<?' . meta_filter(), [$prev, $since]);
    $newInstalls = (int) q1('SELECT COUNT(*) FROM (SELECT install,MIN(ts) f FROM events GROUP BY install HAVING f>=?) t', [$since]);
    $newPrev     = (int) q1('SELECT COUNT(*) FROM (SELECT install,MIN(ts) f FROM events GROUP BY install HAVING f>=? AND f<?) t', [$prev, $since]);
    $returning   = (int) q1('SELECT COUNT(*) FROM (SELECT install,COUNT(DISTINCT day) d FROM events GROUP BY install HAVING d>=2) t');
    $eventsAll   = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE 1=1' . meta_filter());
    $scans   = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE event=\'scan\' AND ts>=?', [$now - 86400]);
    $ocr     = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE event IN (\'ocr_ok\',\'ocr_fail\',\'setOcr\') AND ts>=?', [$now - 86400]);
    $pdfs    = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE event IN (\'savePdf\',\'savePdfSelected\',\'savePdfHere\',\'imagesToPdf\') AND ts>=?', [$now - 86400]);
    $crashes = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE event=\'crash\' AND ts>=?', [$since]);
    return [
        'installs'    => $installs,
        'online'      => (int) q1('SELECT COUNT(*) FROM live WHERE ts>=?', [$now - 300]),
        'active_today'=> (int) q1('SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 86400]),
        'active_7'    => (int) q1('SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 7 * 86400]),
        'active_30'   => (int) q1('SELECT COUNT(DISTINCT install) FROM events WHERE ts>=?', [$now - 30 * 86400]),
        'new'         => $newInstalls,
        'new_delta'   => delta_pct($newInstalls, $newPrev),
        'returning'   => $returning,
        'retention'   => pct($returning, $installs),
        'events'      => $eventsRange,
        'events_delta'=> delta_pct($eventsRange, $eventsPrev),
        'events_all'  => $eventsAll,
        'avg_user'    => $installs > 0 ? round($eventsAll / $installs, 1) : 0,
        'scans_today' => $scans,
        'ocr_today'   => $ocr,
        'pdf_today'   => $pdfs,
        'crashes'     => $crashes,
        'crash_rate'  => $eventsRange > 0 ? round($crashes / $eventsRange * 100, 2) : 0,
        'sessions'    => (int) q1('SELECT COUNT(*) FROM live WHERE ts>=?', [$now - 1800]),
        'unread_fb'   => (int) q1('SELECT COUNT(*) FROM feedback WHERE seen=0'),
        'countries'   => (int) q1("SELECT COUNT(DISTINCT country) FROM geo WHERE country<>'' AND country<>'??'"),
        'db_bytes'    => db_size_bytes(),
        'avg_scan_ms' => round(event_stats('scan_ms')['avg']),
        'avg_ocr_ms'  => round(event_stats('ocr_ms')['avg']),
        'avg_pdf_kb'  => round(event_stats('pdf_kb')['avg']),
        'avg_pages'   => avg_pages_per_scan(),
    ];
}

/** Approx DB size in bytes (information_schema). */
function db_size_bytes(): int {
    global $DB_NAME;
    try {
        return (int) q1('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=?', [$DB_NAME]);
    } catch (Throwable $e) { return 0; }
}

/** Daily events series padded to $span buckets. */
function daily_series(int $span): array {
    $now = time();
    $rows = qa('SELECT day, SUM(cnt) c FROM events WHERE ts>=?' . meta_filter() . ' GROUP BY day', [$now - $span * 86400]);
    $m = []; foreach ($rows as $r) $m[$r['day']] = (int)$r['c'];
    $out = [];
    for ($i = $span - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', $now - $i * 86400); $out[] = ['label' => gmdate('d M', $now - $i * 86400), 'v' => (int)($m[$d] ?? 0)]; }
    return $out;
}
/** Daily distinct-active-users series. */
function daily_users(int $span): array {
    $now = time();
    $rows = qa('SELECT day, COUNT(DISTINCT install) u FROM events WHERE ts>=? GROUP BY day', [$now - $span * 86400]);
    $m = []; foreach ($rows as $r) $m[$r['day']] = (int)$r['u'];
    $out = [];
    for ($i = $span - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', $now - $i * 86400); $out[] = ['label' => gmdate('d M', $now - $i * 86400), 'v' => (int)($m[$d] ?? 0)]; }
    return $out;
}
/** Cumulative install growth. */
function growth_series(): array {
    $rows = qa('SELECT firstday, COUNT(*) c FROM (SELECT install,MIN(day) firstday FROM events GROUP BY install) t GROUP BY firstday ORDER BY firstday');
    $out = []; $cum = 0;
    foreach ($rows as $r) { $cum += (int)$r['c']; $out[] = ['label' => $r['firstday'], 'v' => $cum]; }
    return $out;
}
function feature_usage(int $since, int $limit = 25): array {
    return qa('SELECT event, SUM(cnt) c, COUNT(DISTINCT install) u FROM events WHERE ts>=?' . meta_filter() . ' GROUP BY event ORDER BY c DESC LIMIT ' . (int)$limit, [$since]);
}
function version_active(): array {
    return qa('SELECT version, COUNT(DISTINCT install) u FROM events WHERE ts>=? GROUP BY version ORDER BY u DESC LIMIT 12', [time() - 7 * 86400]);
}
function version_all(): array {
    return qa('SELECT version, COUNT(DISTINCT install) u FROM events GROUP BY version ORDER BY version DESC LIMIT 20');
}
function latest_version(): string {
    foreach (version_all() as $r) if ($r['version'] !== '') return $r['version'];
    return '';
}
function os_dist(): array { return qa('SELECT os, COUNT(DISTINCT install) u FROM events GROUP BY os ORDER BY u DESC LIMIT 12'); }
function country_dist(): array { return qa("SELECT country, COUNT(*) u FROM geo WHERE country<>'' AND country<>'??' GROUP BY country ORDER BY u DESC LIMIT 15"); }
function hours_dist(int $since): array {
    $rows = qa('SELECT HOUR(FROM_UNIXTIME(ts)) hr, SUM(cnt) c FROM events WHERE ts>=?' . meta_filter() . ' GROUP BY hr', [$since]);
    $h = array_fill(0, 24, 0); foreach ($rows as $r) $h[(int)$r['hr']] = (int)$r['c'];
    return $h;
}
function funnel(): array {
    $installed = (int) q1('SELECT COUNT(DISTINCT install) FROM events');
    return [
        ['Installed', $installed],
        ['Scanned',   (int) q1("SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('scan','addPhoto','import','importPath')")],
        ['Saved',     (int) q1("SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('savePdf','savePdfSelected','saveImages','savePdfHere','savePagesToFolder','imagesToPdf')")],
        ['Shared',    (int) q1("SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('share','shareWhatsapp','shareWindows','sharePhone')")],
    ];
}
function cohorts(int $limit = 8): array {
    return qa('SELECT MIN(mn) wkstart, COUNT(*) n, SUM(CASE WHEN mx-mn>=604800 THEN 1 ELSE 0 END) ret
               FROM (SELECT install,MIN(ts) mn,MAX(ts) mx FROM events GROUP BY install) t
               GROUP BY YEARWEEK(FROM_UNIXTIME(mn),3) ORDER BY wkstart DESC LIMIT ' . (int)$limit);
}
function top_installs(int $since, int $limit = 10): array {
    return qa('SELECT install, SUM(cnt) c, COUNT(DISTINCT day) days, MAX(version) ver FROM events WHERE ts>=?' . meta_filter() . ' GROUP BY install ORDER BY c DESC LIMIT ' . (int)$limit, [$since]);
}
/** Paginated event feed with optional filters. */
function events_page(array $f, int $page, int $per = 40): array {
    $c = '1=1' . meta_filter(); $a = [];
    if (!empty($f['q'])) { $c .= ' AND event LIKE ?'; $a[] = '%' . $f['q'] . '%'; }
    if (!empty($f['version'])) { $c .= ' AND version=?'; $a[] = $f['version']; }
    if (!empty($f['os'])) { $c .= ' AND os=?'; $a[] = $f['os']; }
    if (!empty($f['since'])) { $c .= ' AND ts>=?'; $a[] = (int)$f['since']; }
    $total = (int) q1("SELECT COUNT(*) FROM events WHERE $c", $a);
    $off = max(0, ($page - 1) * $per);
    $rows = qa("SELECT ts,event,version,os,install,cnt FROM events WHERE $c ORDER BY id DESC LIMIT $per OFFSET $off", $a);
    return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int)ceil($total / $per)), 'page' => $page];
}
function distinct_versions(): array { return array_column(qa('SELECT DISTINCT version FROM events ORDER BY version DESC LIMIT 40'), 'version'); }
function distinct_os(): array { return array_column(qa('SELECT DISTINCT os FROM events ORDER BY os LIMIT 40'), 'os'); }

/** Scanner analytics from src_* + color events (data grows as clients update). */
function scanner_sources(): array {
    $rows = qa("SELECT event, SUM(cnt) c FROM events WHERE event LIKE 'src\\_%' GROUP BY event ORDER BY c DESC");
    $out = []; foreach ($rows as $r) $out[] = ['name' => ucfirst(substr($r['event'], 4)), 'c' => (int)$r['c']];
    return $out;
}
function pages_scanned_total(): int { return (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='pages_scanned'"); }
function ocr_totals(): array {
    return [
        'runs'   => (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event IN ('ocr_ok','ocr_fail')"),
        'onusers'=> (int) q1("SELECT COUNT(DISTINCT install) FROM events WHERE event IN ('ocr_ok','ocr_fail','setOcr')"),
    ];
}
/** Sum + count + average of a value-carrying event (e.g. scan_ms, pdf_kb). */
function event_stats(string $ev): array {
    $r = qr('SELECT COALESCE(SUM(cnt),0) s, COUNT(*) c FROM events WHERE event=?', [$ev]) ?: ['s' => 0, 'c' => 0];
    $s = (int)$r['s']; $c = (int)$r['c'];
    return ['sum' => $s, 'count' => $c, 'avg' => $c > 0 ? $s / $c : 0];
}
/** Grouped totals for prefixed events (dpi_, color_, ocr_lang_ …). */
function prefixed_events(string $prefix): array {
    $rows = qa('SELECT event, SUM(cnt) c FROM events WHERE event LIKE ? GROUP BY event ORDER BY c DESC', [$prefix . '%']);
    $out = []; foreach ($rows as $r) $out[] = ['name' => substr($r['event'], strlen($prefix)), 'c' => (int)$r['c']];
    return $out;
}
function avg_pages_per_scan(): float {
    $scans = (int) q1("SELECT COUNT(*) FROM events WHERE event='scan'");
    return $scans > 0 ? round(pages_scanned_total() / $scans, 1) : 0;
}

/** Device analytics (from the devices table — populated Phase 2). */
function device_field(string $col): array {
    $ok = ['os', 'arch', 'cpu_cores', 'ram_mb', 'screen', 'monitors', 'lang', 'tz', 'scanner'];
    if (!in_array($col, $ok, true)) return [];
    return qa("SELECT `$col` v, COUNT(*) u FROM devices WHERE `$col` IS NOT NULL AND `$col`<>'' GROUP BY `$col` ORDER BY u DESC LIMIT 15");
}
function devices_count(): int { return (int) q1('SELECT COUNT(*) FROM devices'); }

/** Notifications derived from live state (no separate table needed). */
function notifications(): array {
    $n = []; $now = time();
    $latest = latest_version();
    $crashes24 = (int) q1("SELECT COALESCE(SUM(cnt),0) FROM events WHERE event='crash' AND ts>=?", [$now - 86400]);
    $ev24 = (int) q1('SELECT COALESCE(SUM(cnt),0) FROM events WHERE ts>=?', [$now - 86400]);
    $unread = (int) q1('SELECT COUNT(*) FROM feedback WHERE seen=0');
    $db = db_size_bytes();
    if ($crashes24 > 0) $n[] = ['t' => 'crash', 'sev' => 'bad', 'title' => $crashes24 . ' crash(es) in 24h', 'sub' => 'Check the Crash report', 'link' => '?page=reports'];
    if ($ev24 > 0 && $crashes24 / max(1, $ev24) > 0.05) $n[] = ['t' => 'errrate', 'sev' => 'warn', 'title' => 'High error rate', 'sub' => 'Crashes above 5% of events', 'link' => '?page=health'];
    if ($unread > 0) $n[] = ['t' => 'fb', 'sev' => 'info', 'title' => $unread . ' unread feedback', 'sub' => 'New messages from users', 'link' => '?page=events&tab=feedback'];
    if ($db > 200 * 1024 * 1024) $n[] = ['t' => 'db', 'sev' => 'warn', 'title' => 'Database is large', 'sub' => human_bytes($db) . ' — consider archiving', 'link' => '?page=health'];
    if (!$n) $n[] = ['t' => 'ok', 'sev' => 'good', 'title' => 'All systems normal', 'sub' => 'No alerts right now', 'link' => '#'];
    return $n;
}
