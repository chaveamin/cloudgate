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
        'enable_login', 'enable_register', 'enable_pwreset', 'enable_contact', 'enable_ticket', 'enable_cart',
        'custom_login_sel', 'custom_register_sel', 'custom_pwreset_sel', 'custom_contact_sel', 'custom_ticket_sel', 'custom_cart_sel',
        'mode_login', 'mode_register', 'mode_pwreset', 'mode_contact', 'mode_ticket', 'mode_cart',
        'rate_limit_enabled', 'rate_limit_max', 'rate_limit_window'
    ];

    // Handle AJAX: test keys
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_keys') {
        header('Content-Type: application/json');

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

    // Render Form
    echo '<style>
        @font-face { font-family: "bakh"; src: url(' . $assetsdir . 'YekanBakh.woff2' . '); font-weight: 100 900; font-display: fallback; }
        #contentarea > div > h1:first-child { display: none; }
        .cloudgate-card, select { height: 100%; background: #fff; padding: 25px; border-radius: 18px; border: 3px solid oklch(0.2103 0.0059 285.89 / 10%); margin-bottom: 20px; }
        .cloudgate-card h3 { margin-top: 0; border-bottom: 1px solid oklch(0.2103 0.0059 285.89 / 10%); padding-bottom: 15px; margin-bottom: 20px; color: #27272a; font-size: 22px; font-weight: 600; }
        label { font-size: 15px; display: block; font-weight: 600; margin-bottom: 8px; color: #3f3f46; }
        input[type="text"], input[type="password"], select { font-family: monospace; width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; font-size: 14px; }
        input[type="text"]:focus, input[type="password"]:focus, select:focus { box-shadow: 0 0 0 2px #ff7737; outline: none; }
        .help-block { color: #888; font-size: 0.85em; margin-top: 5px; }
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
        <form method="post" action="">
            <input type="hidden" name="action" value="save">
            
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
                        <span>نمایش در صفحه ورود</span>
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
                        <span>نمایش در صفحه ثبت‌نام</span>
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
                        <span>نمایش در صفحه بازنشانی رمز عبور</span>
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
                        <span>نمایش در صفحه ارتباط</span>
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
                        <span>نمایش در صفحه ارسال تیکت</span>
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
                        <span>نمایش در صفحه سبد خرید</span>
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
                <p class="help-block">جلوگیری از اتک‌ها با محدود کردن تعداد تلاش‌های ناموفق از یک آدرس آی پی</p>

                <div class="row">
                    <div class="form-group">
                        <label>حداکثر تلاش ناموفق</label>
                        <input type="text" name="rate_limit_max" value="' . htmlspecialchars($settings['rate_limit_max'] ?: '5') . '" placeholder="5">
                        <small>تعداد تلاش‌های ناموفق مجاز در بازه زمانی</small>
                    </div>
                    <div class="form-group">
                        <label>بازه زمانی (دقیقه)</label>
                        <input type="text" name="rate_limit_window" value="' . htmlspecialchars($settings['rate_limit_window'] ?: '5') . '" placeholder="5">
                        <small>پنجره زمانی برای شمارش تلاش‌ها</small>
                    </div>
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

                    btn.disabled = true;
                    box.className = "tg-loading";
                    box.style.display = "block";
                    box.textContent = "در حال بررسی...";

                    fetch(window.location.href, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: "action=test_keys&secret_key=" + encodeURIComponent(secret)
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
    </div>';
}
