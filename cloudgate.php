<?php

use WHMCS\Database\Capsule;
use WHMCS\Config\Setting;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function cloudgate_config()
{
    return [
        'name' => 'کلودگیت',
        'description' => 'مدیریت ویجت کپچای کلودفلر(turnstile)',
        'author' => 'Amin Chavepour',
        'language' => 'farsi',
        'version' => '1.0.0',
        'fields' => []
    ];
}

function cloudgate_activate()
{
    try {
        Capsule::schema()->create('mod_cloudgate_logs', function ($table) {
            $table->increments('id');
            $table->string('page', 50);
            $table->string('ip', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['page', 'created_at']);
        });
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'خطا در ساخت جدول: ' . $e->getMessage()];
    }

    return ['status' => 'success', 'description' => 'ماژول کلودگیت با موفقیت فعال شد.'];
}

function cloudgate_deactivate()
{
    try {
        Capsule::schema()->dropIfExists('mod_cloudgate_logs');
    } catch (\Exception $e) {}

    return ['status' => 'success', 'description' => 'ماژول کلودگیت با موفقیت غیرفعال شد.'];
}

function cloudgate_output($vars)
{
    $moduleName = 'cloudgate';
    $validSettings = [
        'site_key', 'secret_key', 'theme', 'size',
        'enable_login', 'enable_register', 'enable_pwreset', 'enable_contact', 'enable_ticket', 'enable_cart', 'enable_domain',
        'custom_login_sel', 'custom_register_sel', 'custom_pwreset_sel', 'custom_contact_sel', 'custom_ticket_sel', 'custom_cart_sel', 'custom_domain_sel',
        'mode_login', 'mode_register', 'mode_pwreset', 'mode_contact', 'mode_ticket', 'mode_cart', 'mode_domain',
        'rate_limit_enabled', 'rate_limit_max', 'rate_limit_window',
        'ip_whitelist', 'ip_blacklist',
        'api_key'
    ];

    // Handle REST API
    if (isset($_GET['module']) && $_GET['module'] === 'cloudgate' && isset($_GET['action']) && $_GET['action'] === 'api') {
        header('Content-Type: application/json; charset=utf-8');

        $apiKey = $_GET['key'] ?? $_POST['key'] ?? '';
        $storedKey = cloudgate_get_setting('api_key');

        if (!$storedKey || $apiKey !== $storedKey) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid API key']);
            exit;
        }

        $type = $_GET['type'] ?? $_POST['type'] ?? '';

        switch ($type) {
            case 'status':
                $totalFailures = (int) Capsule::table('mod_cloudgate_logs')->count();
                $recentFailures = (int) Capsule::table('mod_cloudgate_logs')
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                    ->count();
                $topIPs = Capsule::table('mod_cloudgate_logs')
                    ->select('ip', Capsule::raw('COUNT(*) as attempts'))
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                    ->groupBy('ip')
                    ->orderByDesc('attempts')
                    ->take(10)
                    ->get()
                    ->pluck('attempts', 'ip')
                    ->toArray();
                $pages = Capsule::table('mod_cloudgate_logs')
                    ->select('page', Capsule::raw('COUNT(*) as attempts'))
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                    ->groupBy('page')
                    ->orderByDesc('attempts')
                    ->get()
                    ->pluck('attempts', 'page')
                    ->toArray();

                echo json_encode([
                    'status' => 'ok',
                    'total_failures' => $totalFailures,
                    'failures_24h' => $recentFailures,
                    'top_ips_24h' => $topIPs,
                    'by_page_24h' => $pages,
                    'rate_limit_enabled' => cloudgate_get_setting('rate_limit_enabled') === 'on',
                    'rate_limit_max' => (int)(cloudgate_get_setting('rate_limit_max') ?: 5),
                    'rate_limit_window' => (int)(cloudgate_get_setting('rate_limit_window') ?: 5),
                ]);
                break;

            case 'logs':
                $page = $_GET['page'] ?? '';
                $ip = $_GET['ip'] ?? '';
                $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
                $offset = max(0, (int)($_GET['offset'] ?? 0));

                $query = Capsule::table('mod_cloudgate_logs');
                if ($page !== '' && in_array($page, ['login', 'register', 'pwreset', 'contact', 'ticket', 'cart', 'domain'], true)) {
                    $query->where('page', $page);
                }
                if ($ip !== '') {
                    $query->where('ip', $ip);
                }
                $total = $query->count();
                $entries = $query->orderByDesc('created_at')->skip($offset)->take($limit)->get();

                echo json_encode([
                    'status' => 'ok',
                    'total' => $total,
                    'offset' => $offset,
                    'limit' => $limit,
                    'entries' => $entries->toArray(),
                ]);
                break;

            case 'ip_check':
                $checkIp = $_GET['ip'] ?? '';
                if ($checkIp === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Missing ip parameter']);
                    exit;
                }
                $failures = (int) Capsule::table('mod_cloudgate_logs')
                    ->where('ip', $checkIp)
                    ->count();
                $recentFailures = (int) Capsule::table('mod_cloudgate_logs')
                    ->where('ip', $checkIp)
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-24 hours')))
                    ->count();
                $windowMinutes = (int)(cloudgate_get_setting('rate_limit_window') ?: 5);
                $recentWindow = (int) Capsule::table('mod_cloudgate_logs')
                    ->where('ip', $checkIp)
                    ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes")))
                    ->count();
                $maxAttempts = (int)(cloudgate_get_setting('rate_limit_max') ?: 5);

                echo json_encode([
                    'status' => 'ok',
                    'ip' => $checkIp,
                    'total_failures' => $failures,
                    'failures_24h' => $recentFailures,
                    'failures_in_window' => $recentWindow,
                    'rate_limit_max' => $maxAttempts,
                    'rate_limit_window' => $windowMinutes,
                    'would_be_rate_limited' => $recentWindow >= $maxAttempts && cloudgate_get_setting('rate_limit_enabled') === 'on',
                    'is_whitelisted' => cloudgate_is_whitelisted($checkIp),
                    'is_blacklisted' => cloudgate_is_blacklisted($checkIp),
                ]);
                break;

            default:
                http_response_code(400);
                echo json_encode([
                    'error' => 'Invalid type',
                    'available_types' => ['status', 'logs', 'ip_check'],
                ]);
        }
        exit;
    }

    // Handle AJAX: test keys
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_keys') {
        header('Content-Type: application/json');

        // Validate nonce
        $submittedNonce = $_POST['cloudgate_nonce'] ?? '';
        $validNonce = $_SESSION['cloudgate_test_nonce'] ?? '';
        unset($_SESSION['cloudgate_test_nonce']);

        if (!$validNonce || !hash_equals($validNonce, $submittedNonce)) {
            echo json_encode(['ok' => false, 'message' => 'درخواست نامعتبر است. صفحه را رفرش کنید.']);
            exit;
        }

        $secret = isset($_POST['secret_key']) ? trim($_POST['secret_key']) : cloudgate_get_setting('secret_key');

        if (!$secret) {
            echo json_encode(['ok' => false, 'message' => 'Secret Key وارد نشده است.']);
            exit;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret'   => $secret,
            'response' => 'fake_token_for_validation',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $result = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            echo json_encode(['ok' => false, 'message' => 'خطای شبکه: ' . $curlError]);
            exit;
        }

        $json = json_decode($result, true);
        $errors = $json['error-codes'] ?? [];

        if (in_array('invalid-input-secret', $errors, true)) {
            echo json_encode(['ok' => false, 'message' => 'Secret Key نامعتبر است.']);
        } elseif (in_array('invalid-input-response', $errors, true) || ($json['success'] ?? false)) {
            echo json_encode(['ok' => true, 'message' => '✓ Secret Key معتبر است.']);
        } else {
            $code = implode(', ', $errors) ?: 'unknown';
            echo json_encode(['ok' => false, 'message' => 'Secret Key نامعتبر است. کد خطا: ' . $code]);
        }
        exit;
    }

    // Handle Save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
        foreach ($validSettings as $setting) {
            $value = isset($_POST[$setting]) ? trim($_POST[$setting]) : '';
            
            // Checkbox logic for WHMCS 'yesno' fields
            if (strpos($setting, 'enable_') === 0) {
                 $value = ($value === 'on') ? 'on' : '';
            }

            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $moduleName, 'setting' => $setting],
                ['value' => $value]
            );
        }
        echo '<div class="alert">تنظیمات با موفقیت ذخیره شدند</div>';
    }

    // Retrieve settings
    $settings = [];
    foreach ($validSettings as $key) {
        $settings[$key] = Capsule::table('tbladdonmodules')->where('module', $moduleName)->where('setting', $key)->value('value');
    }

    $systemUrl = Setting::getValue('SystemURL');
    $assetsdir = $systemUrl . '/modules/addons/cloudgate/assets/';

    // Generate nonce for AJAX protection
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['cloudgate_test_nonce'] = $nonce;

    // Handle clear logs
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_logs') {
        Capsule::table('mod_cloudgate_logs')->truncate();
        echo '<div class="alert">لاگ‌ها با موفقیت پاک شدند</div>';
    }

    // Handle CSV export
    if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
        $exportPage = $_GET['log_filter_page'] ?? '';
        $exportIp = $_GET['log_filter_ip'] ?? '';

        $exportQuery = Capsule::table('mod_cloudgate_logs');
        if ($exportPage !== '' && in_array($exportPage, ['login', 'register', 'pwreset', 'contact', 'ticket', 'cart'], true)) {
            $exportQuery->where('page', $exportPage);
        }
        if ($exportIp !== '') {
            $exportQuery->where('ip', 'like', '%' . $exportIp . '%');
        }
        $exportEntries = $exportQuery->orderByDesc('created_at')->get();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cloudgate_logs_' . date('Y-m-d_H-i') . '.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBF) . chr(0xBD));
        fputcsv($output, ['صفحه', 'آدرس آی پی', 'User Agent', 'تاریخ']);

        $pageLabels = [
            'login' => 'Login', 'register' => 'Register', 'pwreset' => 'Password Reset',
            'contact' => 'Contact', 'ticket' => 'Ticket', 'cart' => 'Cart',
        ];

        foreach ($exportEntries as $entry) {
            fputcsv($output, [
                $pageLabels[$entry->page] ?? $entry->page,
                $entry->ip,
                $entry->user_agent ?? '',
                date('Y-m-d H:i:s', strtotime($entry->created_at)),
            ]);
        }

        fclose($output);
        exit;
    }

    // Log viewer query
    $logPage = max(1, (int)($_GET['log_page'] ?? 1));
    $logPerPage = 20;
    $logOffset = ($logPage - 1) * $logPerPage;
    $logFilterPage = $_GET['log_filter_page'] ?? '';
    $logFilterIp = $_GET['log_filter_ip'] ?? '';

    $logQuery = Capsule::table('mod_cloudgate_logs');
    if ($logFilterPage !== '' && in_array($logFilterPage, ['login', 'register', 'pwreset', 'contact', 'ticket', 'cart'], true)) {
        $logQuery->where('page', $logFilterPage);
    }
    if ($logFilterIp !== '') {
        $logQuery->where('ip', 'like', '%' . $logFilterIp . '%');
    }
    $logTotal = (int) $logQuery->count();
    $logTotalPages = max(1, (int)ceil($logTotal / $logPerPage));
    $logEntries = $logQuery->orderByDesc('created_at')->skip($logOffset)->take($logPerPage)->get();

    // Log tab active state
    $activeTab = ($_GET['tab'] ?? '') === 'logs' ? 'logs' : 'settings';

    // Render Form
    echo '<style>
        @font-face { font-family: "bakh"; src: url(' . $assetsdir . 'YekanBakh.woff2' . '); font-weight: 100 900; font-display: fallback; }
        #contentarea > div > h1:first-child { display: none; }
        .cloudgate-card, select { height: 100%; background: #fff; padding: 25px; border-radius: 18px; border: 3px solid oklch(0.2103 0.0059 285.89 / 10%); margin-bottom: 20px; }
        .cloudgate-card h3 { margin-top: 0; border-bottom: 1px solid oklch(0.2103 0.0059 285.89 / 10%); padding-bottom: 15px; margin-bottom: 20px; color: #27272a; font-size: 22px; font-weight: 600; }
        label { font-size: 15px; display: block; font-weight: 600; margin-bottom: 8px; color: #3f3f46; }
        input[type="text"], input[type="password"], select { font-family: monospace; width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 14px; }
        input[type="text"]:focus, input[type="password"]:focus, select:focus { box-shadow: 0 0 0 2px #ff7737; outline: none; }
        .help-block { color: #888; font-size: 0.9em; margin-top: 5px; }
        .row { display: flex; align-items: flex-start; column-gap: 24px; }
        .row > * { width: 100%; }
        .col-half { flex: 0 0 50%; padding: 0 15px; box-sizing: border-box; }
        .cloudgate-card, select, .btn-save, small, .alert { font-family: "bakh"; }
        small { color: #71717a; }
        .alert { background-color: oklch(0.7681 0.2044 130.85 / 15%); color: oklch(0.5322 0.1405 131.59); border-radius: 12px; text-align: right; font-family: "bakh"; font-weight: 600; }
        .cloudgate-header { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 16px; border: 2px solid oklch(0.2103 0.0059 285.89 / 10%); border-radius: 12px; margin-bottom: 24px; }
        .cloudgate-header img { width: 48px; }
        .cloudgate-header .links {display: flex; align-items: center; gap: 12px; }
        
        /* Switch UI */
        .toggle-row { display: flex; align-items: center; padding: 12px 0; gap: 12px; border-bottom: 1px solid oklch(0.2103 0.0059 285.89 / 5%); }
        .toggle-row span { font-size: 16px; font-weight: 500; }
        .toggle-row:last-child { border-bottom: none; }
        .switch { position: relative; display: inline-block; width: 46px; height: 26px; margin-bottom: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #ff5e1f; }
        input:checked + .slider:before { transform: translateX(20px); }
        
        .btn-save { width: 25%; background-color: #ff5e1f; color: #fff; padding: 12px 30px; border: none; border-radius: 10px; cursor: pointer; font-size: 18px; font-weight: 600; transition: background 0.2s; }
        .btn-save:hover { background: #f03806; }
        .actions-row { display: flex; align-items: center; justify-content: space-between; margin-top: 20px; text-align: right; }
        #cloudgate-test-btn { background: #ff5e1f; color: #fff; padding: 10px 22px; border: none; border-radius: 10px; cursor: pointer; font-size: 15px; font-weight: 600; font-family: "bakh"; transition: background 0.2s; }
        #cloudgate-test-btn:hover { background: oklch(21% 0.006 285.885); }
        #cloudgate-test-result { padding: 10px 16px; border-radius: 10px; font-weight: 600; display: none; }
        .tg-ok  { background: oklch(84.1% 0.238 128.85 / 20%); color: oklch(53.2% 0.157 131.589); box-shaow: 0 0 0 2px oklch(53.2% 0.157 131.589); }
        .tg-err { background: oklch(63.7% 0.237 25.331 / 15%); color: oklch(50.5% 0.213 27.518); box-shaow: 0 0 0 2px oklch(50.5% 0.213 27.518); }
        .tg-loading { background: oklch(44.2% 0.017 285.786 / 10%); color: oklch(37% 0.013 285.805); box-shaow: 0 0 0 2px oklch(37% 0.013 285.805); }
        .test-key { display: flex; align-items: center; justify-content: space-between; }
        .test-emphasis { margin-top: 8px; font-size: 12px; color: oklch(70.5% 0.015 286.067); }
        .mode-select { margin: 0 auto 0 48px; width:auto; padding:6px 10px; }
        @media screen and (max-width: 750px) { .row { flex-direction: column; } .btn-save { width: 100%; } .actions-row { flex-direction: column; row-gap: 16px; } }
        .cg-tabs { transform: translateX(-22px); display: flex; gap: 0; margin-bottom: 12px; }
        .cg-tab { padding: 12px 24px; background: #fff; border: none; border-radius: 12px; cursor: pointer; font-size: 16px; font-weight: 600; font-family: "bakh"; transition: background 0.2s; }
        .cg-tab.active { background: color-mix(in srgb, #f97316 15%, #fff);; color: #c2410c; border: none; }
        .cg-tab-content { display: none; }
        .cg-tab-content.active { display: block; }
        .log-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .log-table th { background: #f4f4f5; padding: 12px; text-align: right; font-weight: 600; border-bottom: 2px solid #e4e4e7; }
        .log-table td { padding: 10px 12px; border-bottom: 1px solid oklch(0.2103 0.0059 285.89 / 5%); }
        .log-table tr:hover { background: oklch(0.2103 0.0059 285.89 / 3%); }
        .log-page-badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .log-page-login { background: oklch(70% 0.15 250 / 15%); color: oklch(50% 0.12 250); }
        .log-page-register { background: oklch(80% 0.15 150 / 15%); color: oklch(45% 0.12 150); }
        .log-page-pwreset { background: oklch(80% 0.12 80 / 15%); color: oklch(50% 0.1 80); }
        .log-page-contact { background: oklch(80% 0.15 300 / 15%); color: oklch(50% 0.12 300); }
        .log-page-ticket { background: oklch(80% 0.12 30 / 15%); color: oklch(50% 0.1 30); }
        .log-page-cart { background: oklch(80% 0.15 60 / 15%); color: oklch(50% 0.12 60); }
        .log-filters { display: flex; gap: 12px; margin-bottom: 16px; align-items: center; }
        .log-filters select, .log-filters input { width: fit-content; margin: 0; padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; font-family: monospace; }
        .log-pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; }
        .log-pagination a, .log-pagination span { padding: 6px 14px; border-radius: 8px; font-size: 14px; text-decoration: none; }
        .log-pagination a { background: #f4f4f5; color: #3f3f46; }
        .log-pagination a:hover { background: #e4e4e7; }
        .log-pagination .current { background: #ff5e1f; color: #fff; font-weight: 600; }
        .log-empty { text-align: center; padding: 40px; color: #71717a; font-size: 16px; }
        .log-count { background: #f4f4f5; padding: 6px 14px; border-radius: 8px; font-size: 14px; color: #71717a; }
        .btn-clear-log { background: oklch(63.7% 0.237 25.331 / 15%); color: oklch(50.5% 0.213 27.518); padding: 8px 18px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; font-family: "bakh"; }
        .btn-clear-log:hover { background: oklch(63.7% 0.237 25.331 / 25%); }
        .log-table__wrapper { overflow: scroll; }

        @media screen and (max-width: 650px) { .log-filters { flex-direction: column; } .log-filters input, .log-filters button,.log-filters select { width: 100%; } }
    </style>';

    echo
    '<div class="cloudgate-wrapper" dir="rtl">
        <header class="cloudgate-header">
            <a href=' . $vars['modulelink'] . '>
                <img src=' . $assetsdir . 'logo.png' . ' alt="logo">
            </a>
            <div class="links">
                <a href="https://github.com/chaveamin/cloudgate" target="_blank" rel="noopener noreferrer">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <g clip-path="url(#clip0_4482_11566)">
                    <path d="M17 1.25H7C3.83 1.25 1.25 3.83 1.25 7V17C1.25 20.17 3.83 22.75 7 22.75H8.5C8.5 22.75 8.57 22.73 8.61 22.73C8.63 22.73 8.65 22.74 8.67 22.74C9.08 22.74 9.42 22.4 9.42 21.99V20.83C9.42 20.83 9.42 19.23 10.27 17.92C10.42 17.7 10.43 17.42 10.31 17.18C10.19 16.94 9.96 16.78 9.69 16.77C7.81 16.64 6.34 14.92 6.34 12.86C6.34 12.19 6.59 11.53 7.07 10.94C7.17 10.81 7.23 10.65 7.24 10.49L7.37 8.3L9.68 9.13C9.82 9.18 9.97 9.19 10.11 9.15C11.35 8.84 12.7 8.85 13.88 9.15C14.03 9.19 14.18 9.18 14.32 9.13L16.7 8.34L16.76 10.49C16.76 10.66 16.82 10.82 16.93 10.94C17.42 11.54 17.66 12.18 17.66 12.86C17.66 14.92 16.19 16.64 14.31 16.77C14.05 16.79 13.81 16.95 13.69 17.18C13.57 17.42 13.59 17.7 13.73 17.92C14.58 19.22 14.58 20.81 14.58 20.83V21.98C14.58 22.39 14.92 22.73 15.33 22.73H17C20.17 22.73 22.75 20.15 22.75 16.99V7C22.75 3.83 20.17 1.25 17 1.25ZM21.25 17.01C21.25 19.35 19.34 21.25 17 21.25H16.08V20.86C16.08 20.79 16.08 19.48 15.49 18.08C17.61 17.42 19.16 15.32 19.16 12.87C19.16 11.94 18.85 11.02 18.25 10.21L18.2 8.31C18.2 7.81 17.94 7.35 17.52 7.06C17.11 6.78 16.58 6.71 16.12 6.88L14.02 7.63C12.73 7.33 11.3 7.33 9.97 7.63L7.88 6.88C7.41 6.71 6.89 6.77 6.47 7.05C6.06 7.33 5.81 7.8 5.8 8.29L5.75 10.19C5.16 11 4.84 11.91 4.84 12.85C4.84 15.3 6.38 17.39 8.51 18.05C7.92 19.45 7.92 20.76 7.92 20.83V21.24H7C4.66 21.24 2.75 19.33 2.75 16.99V7C2.75 4.66 4.66 2.75 7 2.75H17C19.34 2.75 21.25 4.66 21.25 7V17.01Z" fill="black"/>
                    </g>
                    <defs>
                    <clipPath id="clip0_4482_11566">
                    <rect width="24" height="24" fill="black"/>
                    </clipPath>
                    </defs>
                    </svg>
                </a>
                <small>نسخه ' . $vars['version'] . '</small>
            </div>
        </header>

        <div class="cg-tabs">
            <button type="button" class="cg-tab ' . ($activeTab === 'settings' ? 'active' : '') . '" onclick="document.getElementById(\'tab-settings\').style.display=\'block\';document.getElementById(\'tab-logs\').style.display=\'none\';document.querySelectorAll(\'.cg-tab\').forEach(function(t){t.classList.remove(\'active\')});this.classList.add(\'active\');">تنظیمات</button>
            <button type="button" class="cg-tab ' . ($activeTab === 'logs' ? 'active' : '') . '" onclick="document.getElementById(\'tab-settings\').style.display=\'none\';document.getElementById(\'tab-logs\').style.display=\'block\';document.querySelectorAll(\'.cg-tab\').forEach(function(t){t.classList.remove(\'active\')});this.classList.add(\'active\');">لاگ خطاها (' . $logTotal . ')</button>
        </div>

        <div id="tab-settings" class="cg-tab-content ' . ($activeTab === 'settings' ? 'active' : '') . '" style="display:' . ($activeTab === 'settings' ? 'block' : 'none') . ';">
        <form method="post" action="">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="cloudgate_nonce" value="' . $nonce . '">
            
            <div class="cloudgate-card">
                <h3>پیکربندی API</h3>
                <div class="row">
                    <div class="form-group">
                        <label>Site Key</label>
                        <input type="text" name="site_key" value="' . htmlspecialchars($settings['site_key']) . '" placeholder="0x4AAAAAA..." autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label>Secret Key</label>
                        <input type="password" name="secret_key" value="' . htmlspecialchars($settings['secret_key']) . '" placeholder="0x4AAAAAA..." autocomplete="off">
                    </div>
                </div>
                <div class="row">
                    <div class="form-group">
                        <label>تم</label>
                        <select name="theme">
                            <option value="auto" ' . ($settings['theme'] == 'auto' ? 'selected' : '') . '>خودکار</option>
                            <option value="light" ' . ($settings['theme'] == 'light' ? 'selected' : '') . '>روشن</option>
                            <option value="dark" ' . ($settings['theme'] == 'dark' ? 'selected' : '') . '>تاریک</option>
                        </select>                
                    </div>
                    <div class="form-group">
                        <label>اندازه ویجت</label>
                        <select name="size">
                            <option value="normal" '  . ($settings['size'] == 'normal'  ? 'selected' : '') . '>Normal</option>
                            <option value="compact" ' . ($settings['size'] == 'compact' ? 'selected' : '') . '>Compact</option>
                        </select>
                    </div>            
                </div>
                <div class="test-key">
                    <button type="button" id="cloudgate-test-btn" onclick="cloudgateTestKeys()">تست اتصال API</button>
                    <div id="cloudgate-test-result"></div>
                </div>
                <em class="test-emphasis">تست اتصال ممکن است در برخی اپراتورها با خطا مواجه شود</em>
            </div>

            <div class="row">
                <div class="cloudgate-card">
                    <h3>تنظیمات نمایش ویجت</h3>
                    <div class="toggle-row">
                        <span>صفحه ورود</span>
                        <select class="mode-select" name="mode_login">
                            <option value="managed" '  . ($settings['mode_login']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_login'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_login'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>                        
                        <label class="switch">
                            <input type="checkbox" name="enable_login" ' . ($settings['enable_login'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                        <div class="toggle-row">
                        <span>صفحه ثبت‌نام</span>
                        <select class="mode-select" name="mode_register">
                            <option value="managed" '  . ($settings['mode_register']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_register'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_register'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>                        
                        <label class="switch">
                            <input type="checkbox" name="enable_register" ' . ($settings['enable_register'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                        <div class="toggle-row">
                        <span>صفحه بازنشانی رمز عبور</span>
                        <select class="mode-select" name="mode_pwreset">
                            <option value="managed" '  . ($settings['mode_pwreset']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_pwreset'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_pwreset'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>                        
                        <label class="switch">
                            <input type="checkbox" name="enable_pwreset" ' . ($settings['enable_pwreset'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                        <div class="toggle-row">
                        <span>صفحه ارتباط</span>
                        <select class="mode-select" name="mode_contact">
                            <option value="managed" '  . ($settings['mode_contact']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_contact'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_contact'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>                        
                        <label class="switch">
                            <input type="checkbox" name="enable_contact" ' . ($settings['enable_contact'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                        <div class="toggle-row">
                        <span>صفحه ارسال تیکت</span>
                        <select class="mode-select" name="mode_ticket">
                            <option value="managed" '  . ($settings['mode_ticket']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_ticket'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_ticket'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>                        
                        <label class="switch">
                            <input type="checkbox" name="enable_ticket" ' . ($settings['enable_ticket'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                        <div class="toggle-row">
                        <span>صفحه سبد خرید</span>
                        <select class="mode-select" name="mode_cart">
                            <option value="managed" '  . ($settings['mode_cart']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_cart'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_cart'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>
                        <label class="switch">
                            <input type="checkbox" name="enable_cart" ' . ($settings['enable_cart'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="toggle-row">
                        <span>بررسی دامنه</span>
                        <select class="mode-select" name="mode_domain">
                            <option value="managed" '  . ($settings['mode_domain']  == 'managed'        ? 'selected' : '') . '>Managed</option>
                            <option value="non-interactive" ' . ($settings['mode_domain'] == 'non-interactive' ? 'selected' : '') . '>Non-interactive</option>
                            <option value="invisible" ' . ($settings['mode_domain'] == 'invisible'       ? 'selected' : '') . '>Invisible</option>
                        </select>
                        <label class="switch">
                            <input type="checkbox" name="enable_domain" ' . ($settings['enable_domain'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="cloudgate-card">
                    <h3>تنظیمات پیشرفته</h3>
                    <p class="help-block">برای تزریق خودکار ویجت قبل از عناصر خاص، انتخابگر css (مثلاً .btn-submit) را وارد کنید. برای استفاده از تشخیص خودکار، آن را خالی بگذارید.</p>
                    
                    <div class="form-group">
                        <label>انتخابگر فرم ورود</label>
                        <input type="text" name="custom_login_sel" value="' . htmlspecialchars($settings['custom_login_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>انتخابگر فرم ثبت‌نام</label>
                        <input type="text" name="custom_register_sel" value="' . htmlspecialchars($settings['custom_register_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>انتخابگر فرم بازنشانی رمز عبور</label>
                        <input type="text" name="custom_pwreset_sel" value="' . htmlspecialchars($settings['custom_pwreset_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>انتخابگر فرم ارتباط</label>
                        <input type="text" name="custom_contact_sel" value="' . htmlspecialchars($settings['custom_contact_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>انتخابگر فرم ارسال تیکت</label>
                        <input type="text" name="custom_ticket_sel" value="' . htmlspecialchars($settings['custom_ticket_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>انتخابگر فرم سبد خرید</label>
                        <input type="text" name="custom_cart_sel" value="' . htmlspecialchars($settings['custom_cart_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>انتخابگر صفحه بررسی دامنه</label>
                        <input type="text" name="custom_domain_sel" value="' . htmlspecialchars($settings['custom_domain_sel']) . '">
                    </div>
                </div>
            </div>

            <div class="cloudgate-card">
                <h3>درخواست‌ها</h3>

                <div class="toggle-row">
                    <span>فعال‌سازی محدودیت درخواست‌ها</span>
                    <label class="switch">
                        <input type="checkbox" name="rate_limit_enabled" ' . ($settings['rate_limit_enabled'] == 'on' ? 'checked' : '') . '>
                        <span class="slider"></span>
                    </label>
                </div>
                <p class="help-block">جلوگیری از اتک‌ با محدود کردن تعداد تلاش‌های ناموفق از یک آی پی</p>

                <div class="row">
                    <div class="form-group">
                        <label>حداکثر تلاش ناموفق</label>
                        <input type="text" name="rate_limit_max" value="' . htmlspecialchars($settings['rate_limit_max'] ?: '5') . '" placeholder="5">
                        <small>تعداد تلاش‌های ناموفق مجاز</small>
                    </div>
                    <div class="form-group">
                        <label>بازه زمانی (دقیقه)</label>
                        <input type="text" name="rate_limit_window" value="' . htmlspecialchars($settings['rate_limit_window'] ?: '5') . '" placeholder="5">
                        <small>پنجره زمانی برای شمارش تلاش‌ها</small>
                    </div>
                </div>
            </div>

            <div class="cloudgate-card">
                <h3>لیست IP</h3>
                <p class="help-block">آدرس‌های IP را با کاما جدا کنید. از CIDR برای محدوده‌ها استفاده کنید (مثال: 192.168.1.0/24)</p>

                <div class="form-group">
                    <label>لیست سفید</label>
                    <input type="text" name="ip_whitelist" value="' . htmlspecialchars($settings['ip_whitelist'] ?? '') . '" placeholder="127.0.0.1, 10.0.0.0/8">
                    <small>این آی پی بدون کپچا میتوانند به صفحه دسترسی داشته باشند</small>
                </div>
                <div class="form-group">
                    <label>لیست سیاه (مسدود شده)</label>
                    <input type="text" name="ip_blacklist" value="' . htmlspecialchars($settings['ip_blacklist'] ?? '') . '" placeholder="1.2.3.4, 5.6.7.0/24">
                    <small>این آی پی ها کاملاً مسدود میشوند و امکان ارسال درخواست ندارند</small>
                </div>
            </div>

            <div class="cloudgate-card">
                <h3>REST API</h3>
                <p class="help-block">برای استفاده از API، کلید را کپی کرده و در درخواست‌های خود ارسال کنید</p>

                <div class="form-group">
                    <label>API Key</label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="text" name="api_key" id="api-key-input" value="' . htmlspecialchars($settings['api_key'] ?? '') . '" placeholder="برای ساخت کلیک کنید" readonly style="flex:1;font-family:monospace;background:#f4f4f5;">
                        <button type="button" onclick="document.getElementById(\'api-key-input\').value=\'cg_\'+Array.from(crypto.getRandomValues(new Uint8Array(24))).map(function(b){return b.toString(16).padStart(2,\'0\')}).join(\'\');" style="padding:8px 16px;background:#ff5e1f;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:bakh;">ساخت کلید</button>
                    </div>
                    <small>این کلید برای احراز هویت درخواست‌های API استفاده میشود</small>
                </div>

                <label>نمونه درخواست‌ها:</label>
                <div dir="ltr" style="background:#f4f4f5;padding:16px;border-radius:10px;font-size:13px;font-family:monospace;line-height:2;">
                    <div>GET ?module=cloudgate&action=api&key=<span style="color:#ff5e1f;">YOUR_KEY</span>&type=status</div>
                    <div>GET ?module=cloudgate&action=api&key=<span style="color:#ff5e1f;">YOUR_KEY</span>&type=logs&page=login&limit=10</div>
                    <div>GET ?module=cloudgate&action=api&key=<span style="color:#ff5e1f;">YOUR_KEY</span>&type=ip_check&ip=1.2.3.4</div>
                </div>
            </div>

            <div class="actions-row">
                <button type="submit" class="btn-save">ذخیره تنظیمات</button>
            </div>

            <script>
                function cloudgateTestKeys() {
                    var btn = document.getElementById("cloudgate-test-btn");
                    var box = document.getElementById("cloudgate-test-result");
                    var secret = document.querySelector("[name=secret_key]").value.trim();
                    var nonce = document.querySelector("[name=cloudgate_nonce]").value;

                    btn.disabled = true;
                    box.className = "tg-loading";
                    box.style.display = "block";
                    box.textContent = "در حال بررسی...";

                    fetch(window.location.href, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "action=test_keys&secret_key=" + encodeURIComponent(secret) + "&cloudgate_nonce=" + encodeURIComponent(nonce)
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        box.className = data.ok ? "tg-ok" : "tg-err";
                        box.textContent = data.message;
                    })
                    .catch(function() {
                        box.className = "tg-err";
                        box.textContent = "خطای غیرمنتظره در ارسال درخواست.";
                    })
                    .finally(function() { btn.disabled = false; });
                }
            </script>
        </form>
        </div>

        <div id="tab-logs" class="cg-tab-content ' . ($activeTab === 'logs' ? 'active' : '') . '" style="display:' . ($activeTab === 'logs' ? 'block' : 'none') . ';">
            <div class="cloudgate-card">
                <h3>لاگ تلاش‌های ناموفق</h3>

                <form method="get" action="" style="margin-bottom:0;">
                    <input type="hidden" name="module" value="cloudgate">
                    <input type="hidden" name="tab" value="logs">
                    <div class="log-filters">
                        <select name="log_filter_page">
                            <option value="">همه صفحات</option>
                            <option value="login"' . ($logFilterPage === 'login' ? ' selected' : '') . '>ورود</option>
                            <option value="register"' . ($logFilterPage === 'register' ? ' selected' : '') . '>ثبت‌نام</option>
                            <option value="pwreset"' . ($logFilterPage === 'pwreset' ? ' selected' : '') . '>بازنشانی رمز</option>
                            <option value="contact"' . ($logFilterPage === 'contact' ? ' selected' : '') . '>ارتباط</option>
                            <option value="ticket"' . ($logFilterPage === 'ticket' ? ' selected' : '') . '>تیکت</option>
                            <option value="cart"' . ($logFilterPage === 'cart' ? ' selected' : '') . '>سبد خرید</option>
                        </select>
                        <input type="text" name="log_filter_ip" value="' . htmlspecialchars($logFilterIp) . '" placeholder="جستجوی IP...">
                        <button type="submit" style="padding:8px 18px;background:#ff5e1f;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:bakh;">فیلتر</button>
                        <div style="margin-right:auto;"></div>';

    $exportParams = 'module=cloudgate&action=export_csv';
    if ($logFilterPage !== '') $exportParams .= '&log_filter_page=' . urlencode($logFilterPage);
    if ($logFilterIp !== '') $exportParams .= '&log_filter_ip=' . urlencode($logFilterIp);

    echo '                       <a href="?' . $exportParams . '" style="padding:8px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:bakh;text-decoration:none;font-size:14px;font-weight:600;">خروجی CSV</a>
                        <button type="button" class="btn-clear-log" onclick="if(confirm(\'آیا مطمئنید تمام لاگ‌ها پاک شوند؟\')){document.getElementById(\'clear-log-form\').submit();}">پاک کردن لاگ‌ها</button>
                    </div>
                </form>
                <form id="clear-log-form" method="post" action="" style="display:none;">
                    <input type="hidden" name="action" value="clear_logs">
                </form>
                <form id="clear-log-form" method="post" action="" style="display:none;">
                    <input type="hidden" name="action" value="clear_logs">
                </form>';

    if ($logEntries->isEmpty()) {
        echo '<div class="log-empty">هیچ رکوردی یافت نشد</div>';
    } else {
        echo '<table class="log-table">
                <thead>
                    <tr>
                        <th>صفحه</th>
                        <th>آدرس IP</th>
                        <th>User Agent</th>
                        <th>تاریخ</th>
                    </tr>
                </thead>
                <tbody>';

        $pageLabels = [
            'login' => ['ورود', 'login'],
            'register' => ['ثبت‌نام', 'register'],
            'pwreset' => ['بازنشانی رمز', 'pwreset'],
            'contact' => ['ارتباط', 'contact'],
            'ticket' => ['تیکت', 'ticket'],
            'cart' => ['سبد خرید', 'cart'],
        ];

        foreach ($logEntries as $entry) {
            $pageLabel = $pageLabels[$entry->page] ?? [$entry->page, 'login'];
            $pageClass = 'log-page-' . $pageLabel[1];
            $ua = htmlspecialchars($entry->user_agent ?: '—');
            $date = date('Y-m-d H:i', strtotime($entry->created_at));
            echo '<tr>
                    <td><span class="log-page-badge ' . $pageClass . '">' . $pageLabel[0] . '</span></td>
                    <td style="font-family:monospace;">' . htmlspecialchars($entry->ip) . '</td>
                    <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' . $ua . '">' . $ua . '</td>
                    <td style="white-space:nowrap;">' . $date . '</td>
                </tr>';
        }

        echo '</tbody></table>';

        if ($logTotalPages > 1) {
            $baseParams = 'module=cloudgate&tab=logs';
            if ($logFilterPage !== '') $baseParams .= '&log_filter_page=' . urlencode($logFilterPage);
            if ($logFilterIp !== '') $baseParams .= '&log_filter_ip=' . urlencode($logFilterIp);

            echo '<div class="log-pagination">';
            if ($logPage > 1) {
                echo '<a href="?' . $baseParams . '&log_page=' . ($logPage - 1) . '">&#8594; قبلی</a>';
            } else {
                echo '<span></span>';
            }
            echo '<span>صفحه ' . $logPage . ' از ' . $logTotalPages . '</span>';
            if ($logPage < $logTotalPages) {
                echo '<a href="?' . $baseParams . '&log_page=' . ($logPage + 1) . '">بعدی &#8592;</a>';
            } else {
                echo '<span></span>';
            }
            echo '</div>';
        }
    }

    echo '
            </div>
        </div>
    </div>';
}
