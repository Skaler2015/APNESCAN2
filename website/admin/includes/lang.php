<?php
/**
 * ApneScan Admin — lightweight i18n. current_lang() reads the as_lang cookie
 * (default English); t() translates a key, falling back to English then the key
 * itself. Only the UI chrome is translated; analytics data stays as-is.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

function current_lang(): string {
    $l = $_COOKIE['as_lang'] ?? 'en';
    return $l === 'hi' ? 'hi' : 'en';
}

function t(string $key): string {
    static $map = null;
    if ($map === null) $map = i18n_map();
    $l = current_lang();
    return $map[$l][$key] ?? $map['en'][$key] ?? $key;
}

function i18n_map(): array {
    $en = [
        // shell / nav
        'admin_console' => 'Admin Console', 'usage_analytics' => 'Usage Analytics & Control',
        'nav_dashboard' => 'Dashboard', 'nav_product' => 'Product', 'nav_audience' => 'Audience', 'nav_system' => 'System',
        'Overview' => 'Overview', 'Analytics' => 'Analytics', 'Live Users' => 'Live Users',
        'Scanner Analytics' => 'Scanner Analytics', 'OCR Analytics' => 'OCR Analytics', 'Feature Analytics' => 'Feature Analytics',
        'Events & Feedback' => 'Events & Feedback', 'Reports' => 'Reports', 'Versions' => 'Versions',
        'Devices' => 'Devices', 'Operating Systems' => 'Operating Systems', 'Admins & Roles' => 'Admins & Roles',
        'Notifications' => 'Notifications', 'Backup' => 'Backup', 'Export' => 'Export', 'Audit Log' => 'Audit Log',
        'System Health' => 'System Health', 'Settings' => 'Settings', 'Help' => 'Help', 'Search' => 'Search',
        // topbar
        'online' => 'online', 'synced' => 'synced', 'search_ph' => 'Search events, features, versions…',
        'log_out' => 'Log out', 'toggle_theme' => 'Toggle theme', 'notifications' => 'Notifications',
        // ranges
        'range_1' => 'Today', 'range_7' => '7 days', 'range_30' => '30 days', 'range_90' => '90 days',
        'range_365' => '1 year', 'range_all' => 'All time',
        // dashboard
        'ov_sub' => 'Anonymous usage across all ApneScan installs',
        'smart_insights' => 'Smart insights', 'activity_growth' => 'Activity & growth',
        'features_conversion' => 'Features & conversion', 'versions_platforms' => 'Versions & platforms',
        'k_total_installs' => 'Total installs', 'k_online_now' => 'Online now', 'k_today_users' => "Today's users",
        'k_weekly_users' => 'Weekly users', 'k_monthly_users' => 'Monthly users', 'k_new_users' => 'New users',
        'k_returning' => 'Returning users', 'k_sessions' => 'Active sessions', 'k_today_scans' => "Today's scans",
        'k_today_ocr' => "Today's OCR", 'k_today_pdf' => "Today's PDFs", 'k_events' => 'Events',
        'k_avg_events' => 'Avg events / user', 'k_avg_scan' => 'Avg scan time', 'k_avg_pages' => 'Avg pages / scan',
        'k_avg_pdf' => 'Avg PDF size', 'k_avg_ocr' => 'Avg OCR time', 'k_crash_rate' => 'Crash rate',
        'k_countries' => 'Countries', 'k_db_size' => 'Database size',
        'daily_activity' => 'Daily activity', 'install_growth' => 'Install growth',
        'feature_usage' => 'Feature usage', 'adoption_funnel' => 'Adoption funnel',
        'version_adoption' => 'Version adoption', 'operating_systems' => 'Operating systems',
        // common
        'c_download' => 'Download', 'c_export' => 'Export', 'c_save' => 'Save', 'c_cancel' => 'Cancel',
        'c_apply' => 'Apply', 'c_filter' => 'Filter', 'c_delete' => 'Delete', 'c_markread' => 'Mark read',
        'c_csv' => 'CSV', 'c_none' => 'None', 'c_nodata' => 'No data in this range yet.', 'c_nodata2' => 'No data yet.',
        // page subtitles
        'sub_analytics' => 'Interactive trends, retention and geography', 'sub_live' => 'Real-time activity, refreshing every 5 seconds',
        'sub_scanner' => 'How documents are being captured', 'sub_ocr' => 'Text-recognition usage across installs',
        'sub_features' => 'What people actually use', 'sub_events' => 'Every action across all installs, plus messages from users',
        'sub_reports' => 'Download ready-made reports or preview key numbers', 'sub_versions' => 'Which builds are in the field',
        'sub_devices' => 'Hardware & environment of installs', 'sub_os' => 'Windows builds ApneScan runs on',
        'sub_users' => 'Who can access this dashboard, and what they can do', 'sub_notifications' => 'Automatic alerts about crashes, feedback, storage and errors',
        'sub_backup' => 'Protect your analytics data with on-demand, saved and scheduled backups',
        'sub_export' => 'Download the full event dataset in your preferred format',
        'sub_audit' => 'Logins, exports, deletions, settings & password changes', 'sub_health' => 'Database, performance and environment status',
        'sub_settings' => 'Control the app remotely and manage this dashboard', 'sub_help' => 'How this dashboard works',
        // section headers
        's_activity' => 'Activity', 's_retention' => 'Retention & features', 's_timing' => 'Timing & geography',
        's_when' => 'When & where', 's_features_versions' => 'Features & versions', 's_topusers' => 'Top users & live feed',
        's_feedback_inbox' => 'Feedback inbox', 's_app_controls' => 'App controls', 's_perf' => 'Performance & settings',
        's_by_category' => 'By category', 's_snapshot' => 'Snapshot', 's_saved_backups' => 'Saved backups',
        // live page
        'lv_sessions' => 'Active sessions (30m)', 'lv_users_today' => 'Users today', 'lv_events_today' => 'Events today',
        'lv_epm' => 'Events per minute', 'lv_epm_sub' => 'Rolling last 30 minutes · updates live',
        'lv_feed' => 'Live event feed', 'lv_feed_sub' => 'Newest actions across all installs', 'lv_online' => 'online now',
        // table headers + common buttons + presets
        'th_when' => 'When (UTC)', 'th_feature' => 'Feature', 'th_version' => 'Version', 'th_os' => 'OS', 'th_install' => 'Install',
        'p_today' => 'Today', 'p_yesterday' => 'Yesterday', 'p_this_week' => 'This week', 'p_last_week' => 'Last week',
        'p_this_month' => 'This month', 'ev_search_ph' => 'Search feature…', 'ev_all_versions' => 'All versions',
        'ev_all_os' => 'All OS', 'ev_no_match' => 'No matching events.', 'ev_total_match' => 'total events match.',
        'tab_event_log' => 'Event log', 'tab_feedback' => 'Feedback',
        // activity widget (mirrors the in-app one)
        'act_title' => 'Activity', 'col_total' => 'Total', 'col_today' => 'Today',
        'act_scan' => 'Scan', 'act_pdf' => 'PDF Save', 'act_image' => 'Image Save',
        'act_print' => 'Print', 'act_import' => 'Import', 'act_camera' => 'Camera',
        'act_sub' => 'Combined across all installs',
    ];
    $hi = [
        'admin_console' => 'एडमिन कंसोल', 'usage_analytics' => 'उपयोग विश्लेषण और नियंत्रण',
        'nav_dashboard' => 'डैशबोर्ड', 'nav_product' => 'प्रोडक्ट', 'nav_audience' => 'ऑडियंस', 'nav_system' => 'सिस्टम',
        'Overview' => 'अवलोकन', 'Analytics' => 'विश्लेषण', 'Live Users' => 'लाइव यूज़र',
        'Scanner Analytics' => 'स्कैनर विश्लेषण', 'OCR Analytics' => 'OCR विश्लेषण', 'Feature Analytics' => 'फ़ीचर विश्लेषण',
        'Events & Feedback' => 'इवेंट और फ़ीडबैक', 'Reports' => 'रिपोर्ट', 'Versions' => 'वर्शन',
        'Devices' => 'डिवाइस', 'Operating Systems' => 'ऑपरेटिंग सिस्टम', 'Admins & Roles' => 'एडमिन और रोल',
        'Notifications' => 'सूचनाएँ', 'Backup' => 'बैकअप', 'Export' => 'एक्सपोर्ट', 'Audit Log' => 'ऑडिट लॉग',
        'System Health' => 'सिस्टम हेल्थ', 'Settings' => 'सेटिंग्स', 'Help' => 'सहायता', 'Search' => 'खोज',
        'online' => 'ऑनलाइन', 'synced' => 'सिंक', 'search_ph' => 'इवेंट, फ़ीचर, वर्शन खोजें…',
        'log_out' => 'लॉग आउट', 'toggle_theme' => 'थीम बदलें', 'notifications' => 'सूचनाएँ',
        'range_1' => 'आज', 'range_7' => '7 दिन', 'range_30' => '30 दिन', 'range_90' => '90 दिन',
        'range_365' => '1 साल', 'range_all' => 'सभी समय',
        'ov_sub' => 'सभी ApneScan इंस्टॉल का गुमनाम उपयोग',
        'smart_insights' => 'स्मार्ट इनसाइट्स', 'activity_growth' => 'गतिविधि और वृद्धि',
        'features_conversion' => 'फ़ीचर और कन्वर्ज़न', 'versions_platforms' => 'वर्शन और प्लेटफ़ॉर्म',
        'k_total_installs' => 'कुल इंस्टॉल', 'k_online_now' => 'अभी ऑनलाइन', 'k_today_users' => 'आज के यूज़र',
        'k_weekly_users' => 'साप्ताहिक यूज़र', 'k_monthly_users' => 'मासिक यूज़र', 'k_new_users' => 'नए यूज़र',
        'k_returning' => 'लौटने वाले यूज़र', 'k_sessions' => 'सक्रिय सेशन', 'k_today_scans' => 'आज के स्कैन',
        'k_today_ocr' => 'आज का OCR', 'k_today_pdf' => 'आज के PDF', 'k_events' => 'इवेंट',
        'k_avg_events' => 'औसत इवेंट / यूज़र', 'k_avg_scan' => 'औसत स्कैन समय', 'k_avg_pages' => 'औसत पेज / स्कैन',
        'k_avg_pdf' => 'औसत PDF साइज़', 'k_avg_ocr' => 'औसत OCR समय', 'k_crash_rate' => 'क्रैश दर',
        'k_countries' => 'देश', 'k_db_size' => 'डेटाबेस साइज़',
        'daily_activity' => 'दैनिक गतिविधि', 'install_growth' => 'इंस्टॉल वृद्धि',
        'feature_usage' => 'फ़ीचर उपयोग', 'adoption_funnel' => 'अपनाने का फ़नल',
        'version_adoption' => 'वर्शन अपनाना', 'operating_systems' => 'ऑपरेटिंग सिस्टम',
        'c_download' => 'डाउनलोड', 'c_export' => 'एक्सपोर्ट', 'c_save' => 'सेव', 'c_cancel' => 'रद्द करें',
        'c_apply' => 'लागू करें', 'c_filter' => 'फ़िल्टर', 'c_delete' => 'हटाएँ', 'c_markread' => 'पढ़ा हुआ',
        'c_csv' => 'CSV', 'c_none' => 'कोई नहीं', 'c_nodata' => 'इस अवधि में अभी कोई डेटा नहीं।', 'c_nodata2' => 'अभी कोई डेटा नहीं।',
        'sub_analytics' => 'इंटरैक्टिव रुझान, रिटेंशन और भूगोल', 'sub_live' => 'रियल-टाइम गतिविधि, हर 5 सेकंड में रिफ्रेश',
        'sub_scanner' => 'दस्तावेज़ कैसे कैप्चर हो रहे हैं', 'sub_ocr' => 'सभी इंस्टॉल में टेक्स्ट-पहचान का उपयोग',
        'sub_features' => 'लोग असल में क्या उपयोग करते हैं', 'sub_events' => 'सभी इंस्टॉल की हर गतिविधि, और यूज़र के संदेश',
        'sub_reports' => 'तैयार रिपोर्ट डाउनलोड करें या मुख्य आँकड़े देखें', 'sub_versions' => 'फ़ील्ड में कौन-से बिल्ड हैं',
        'sub_devices' => 'इंस्टॉल का हार्डवेयर और वातावरण', 'sub_os' => 'ApneScan किन Windows बिल्ड पर चलता है',
        'sub_users' => 'इस डैशबोर्ड तक किसकी पहुँच है, और वे क्या कर सकते हैं', 'sub_notifications' => 'क्रैश, फ़ीडबैक, स्टोरेज और त्रुटियों की स्वचालित सूचनाएँ',
        'sub_backup' => 'ऑन-डिमांड, सेव्ड और शेड्यूल्ड बैकअप से अपना डेटा सुरक्षित रखें',
        'sub_export' => 'पूरा इवेंट डेटा अपने पसंदीदा फ़ॉर्मेट में डाउनलोड करें',
        'sub_audit' => 'लॉगिन, एक्सपोर्ट, डिलीट, सेटिंग्स और पासवर्ड बदलाव', 'sub_health' => 'डेटाबेस, प्रदर्शन और वातावरण की स्थिति',
        'sub_settings' => 'ऐप को दूर से नियंत्रित करें और इस डैशबोर्ड को प्रबंधित करें', 'sub_help' => 'यह डैशबोर्ड कैसे काम करता है',
        's_activity' => 'गतिविधि', 's_retention' => 'रिटेंशन और फ़ीचर', 's_timing' => 'समय और भूगोल',
        's_when' => 'कब और कहाँ', 's_features_versions' => 'फ़ीचर और वर्शन', 's_topusers' => 'टॉप यूज़र और लाइव फ़ीड',
        's_feedback_inbox' => 'फ़ीडबैक इनबॉक्स', 's_app_controls' => 'ऐप नियंत्रण', 's_perf' => 'प्रदर्शन और सेटिंग्स',
        's_by_category' => 'श्रेणी अनुसार', 's_snapshot' => 'स्नैपशॉट', 's_saved_backups' => 'सेव्ड बैकअप',
        'lv_sessions' => 'सक्रिय सेशन (30 मि)', 'lv_users_today' => 'आज के यूज़र', 'lv_events_today' => 'आज के इवेंट',
        'lv_epm' => 'प्रति मिनट इवेंट', 'lv_epm_sub' => 'पिछले 30 मिनट · लाइव अपडेट',
        'lv_feed' => 'लाइव इवेंट फ़ीड', 'lv_feed_sub' => 'सभी इंस्टॉल की नवीनतम गतिविधियाँ', 'lv_online' => 'अभी ऑनलाइन',
        'th_when' => 'कब (UTC)', 'th_feature' => 'फ़ीचर', 'th_version' => 'वर्शन', 'th_os' => 'OS', 'th_install' => 'इंस्टॉल',
        'p_today' => 'आज', 'p_yesterday' => 'कल', 'p_this_week' => 'इस हफ़्ते', 'p_last_week' => 'पिछले हफ़्ते',
        'p_this_month' => 'इस महीने', 'ev_search_ph' => 'फ़ीचर खोजें…', 'ev_all_versions' => 'सभी वर्शन',
        'ev_all_os' => 'सभी OS', 'ev_no_match' => 'कोई मेल खाता इवेंट नहीं।', 'ev_total_match' => 'कुल इवेंट मेल खाते हैं।',
        'tab_event_log' => 'इवेंट लॉग', 'tab_feedback' => 'फ़ीडबैक',
        'act_title' => 'गतिविधि', 'col_total' => 'कुल', 'col_today' => 'आज',
        'act_scan' => 'स्कैन', 'act_pdf' => 'PDF सेव', 'act_image' => 'इमेज सेव',
        'act_print' => 'प्रिंट', 'act_import' => 'इम्पोर्ट', 'act_camera' => 'कैमरा',
        'act_sub' => 'सभी इंस्टॉल का संयुक्त',
    ];
    return ['en' => $en, 'hi' => $hi];
}
