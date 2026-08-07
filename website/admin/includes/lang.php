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
    ];
    return ['en' => $en, 'hi' => $hi];
}
