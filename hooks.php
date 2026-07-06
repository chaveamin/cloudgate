<?php

use WHMCS\Database\Capsule;
require_once __DIR__ . '/logger.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function cloudgate_widget_html($page)
{
    $siteKey = cloudgate_get_site_key();
    $theme   = cloudgate_get_setting('theme') ?: 'auto';
    $mode    = cloudgate_get_setting('mode_' . $page) ?: 'managed';

    $isInvisible = ($mode === 'invisible');
    $wrapStyle = $isInvisible ? 'display:none;' : '';
    $widgetStyle = 'display:inline-block;';
    $size = ($mode === 'non-interactive') ? 'compact' : (cloudgate_get_setting('size') ?: 'normal');

    return '<div style="' . $wrapStyle . '">'
        . '<div class="cf-turnstile" '
        . 'style="' . $widgetStyle . '" '
        . 'data-sitekey="' . htmlspecialchars($siteKey) . '" '
        . 'data-theme="' . htmlspecialchars($theme) . '" '
        . 'data-appearance="' . ($mode === 'invisible' ? 'hidden' : 'always') . '" '
        . 'data-execution="' . ($mode === 'invisible' ? 'execute' : 'render') . '" '
        . 'data-size="' . $size . '" '
        . '></div>'
        . '</div>';
}

function cloudgate_verify($response)
{
    $secretKey = Capsule::table('tbladdonmodules')->where('module', 'cloudgate')->where('setting', 'secret_key')->value('value');
    if (!$secretKey) return false;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $secretKey,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($result, true);
    return $json['success'] ?? false;
}

function cloudgate_get_setting($name)
{
    // Simple cache-like static variable could be used but Capsule is fast enough for low traffic
    return Capsule::table('tbladdonmodules')->where('module', 'cloudgate')->where('setting', $name)->value('value');
}

function cloudgate_is_enabled($pageSetting)
{
    return cloudgate_get_setting($pageSetting) === 'on';
}

function cloudgate_get_site_key()
{
    return cloudgate_get_setting('site_key');
}

function cloudgate_lang_root()
{
    if (defined('ROOTDIR') && is_string(ROOTDIR) && ROOTDIR !== '') {
        return ROOTDIR;
    }
    $resolved = realpath(__DIR__ . '/../../..');
    return $resolved ?: __DIR__;
}

/**
 * @return list<string> Absolute paths to try in order (session language, then english fallback).
 */
function cloudgate_lang_file_candidates()
{
    $langDir = cloudgate_lang_root() . DIRECTORY_SEPARATOR . 'lang';
    if (!is_dir($langDir)) {
        return [];
    }
    $codes = [];
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['Language'])) {
            $codes[] = (string) $_SESSION['Language'];
        }
        if (!empty($_SESSION['locale'])) {
            $codes[] = (string) $_SESSION['locale'];
        }
    }
    $files = [];
    foreach ($codes as $raw) {
        $code = strtolower(preg_replace('/\.php$/', '', trim(basename($raw))));
        $code = preg_replace('/[^a-z0-9_\-]/', '', $code);
        if ($code === '') {
            continue;
        }
        $f = $langDir . DIRECTORY_SEPARATOR . $code . '.php';
        if (is_file($f) && !in_array($f, $files, true)) {
            $files[] = $f;
        }
    }
    $eng = $langDir . DIRECTORY_SEPARATOR . 'english.php';
    if (is_file($eng) && !in_array($eng, $files, true)) {
        $files[] = $eng;
    }
    return $files;
}

function cloudgate_load_lang_array_from_path($path)
{
    if (!is_file($path)) {
        return [];
    }
    $_LANG = [];
    /** @noinspection PhpIncludeInspection */
    include $path;
    return isset($_LANG) && is_array($_LANG) ? $_LANG : [];
}

/**
 * Active WHMCS language pack ($GLOBALS['_LANG']) when available; otherwise lang/*.php from session + english.
 */
function cloudgate_client_lang_array()
{
    if (!empty($GLOBALS['_LANG']) && is_array($GLOBALS['_LANG'])) {
        return $GLOBALS['_LANG'];
    }
    foreach (cloudgate_lang_file_candidates() as $file) {
        $arr = cloudgate_load_lang_array_from_path($file);
        if (!empty($arr)) {
            return $arr;
        }
    }
    return [];
}

/**
 * @param 'prompt'|'error' $key prompt = client JS alert ($_LANG['captchaIncorrect']); error = $_LANG['captcha']['verification']['failed'] with fallbacks
 */
function cloudgate_text($key)
{
    static $resolved = null;
    if ($resolved === null) {
        $lang = cloudgate_client_lang_array();
        $resolved = [
            'prompt' => !empty($lang['captchaIncorrect'])
                ? $lang['captchaIncorrect']
                : 'Complete the captcha and try again.',
            'error' => !empty($lang['captcha']['verification']['failed'])
                ? $lang['captcha']['verification']['failed']
                : (!empty($lang['captchaIncorrect'])
                    ? $lang['captchaIncorrect']
                    : 'Captcha verification failed. Please try again.'),
        ];
    }
    return $resolved[$key] ?? '';
}

function cloudgate_js_string($key)
{
    return json_encode(cloudgate_text($key), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Early interception for pages without dedicated validation hooks.
 * hooks.php is loaded during init.php, BEFORE contact.php processes the form,
 * so this check can block spam before the email is sent.
 */
if (
    php_sapi_name() !== 'cli'
    && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'contact.php'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'send'
    && cloudgate_is_enabled('enable_contact')
) {
    $ip = cloudgate_get_ip();
    if (cloudgate_is_rate_limited($ip)) {
        cloudgate_log_failure('contact');
        unset($_POST['action']);
        $_REQUEST['action'] = '';
    } elseif (!isset($_POST['cf-turnstile-response']) || !cloudgate_verify($_POST['cf-turnstile-response'])) {
        cloudgate_log_failure('contact');
        unset($_POST['action']);
        $_REQUEST['action'] = '';
    }
}

/**
 * Early interception: WHMCS 8+ posts client login to index.php (routed URL), not dologin.php.
 * UserLoginVerification is not documented and is not invoked on current WHMCS builds, so login was effectively unchecked.
 */
if (
    php_sapi_name() !== 'cli'
    && (!defined('ADMINAREA') || !ADMINAREA)
    && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
    && cloudgate_is_enabled('enable_login')
) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $path = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($path, '/admin/') === false && strpos($path, '\\admin\\') === false
        && ($script === 'index.php' || $script === 'dologin.php')
        && isset($_POST['username'], $_POST['password'])
        && is_string($_POST['username']) && is_string($_POST['password'])
        && $_POST['username'] !== '' && $_POST['password'] !== ''
    ) {
        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('login');
            header('Location: login.php?error=captcha');
            exit;
        }
        $token = isset($_POST['cf-turnstile-response']) ? trim((string) $_POST['cf-turnstile-response']) : '';
        if ($token === '' || !cloudgate_verify($token)) {
            cloudgate_log_failure('login');
            header('Location: login.php?error=captcha');
            exit;
        }
    }
}

/**
 * Early interception: password reset email step posts to index.php (routed). ClientAreaPagePasswordReset is for template data,
 * not a reliable pre-submit gate on all WHMCS versions.
 */
if (
    php_sapi_name() !== 'cli'
    && (!defined('ADMINAREA') || !ADMINAREA)
    && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
    && cloudgate_is_enabled('enable_pwreset')
    && isset($_POST['action']) && $_POST['action'] === 'reset'
    && isset($_POST['email']) && is_string($_POST['email']) && trim($_POST['email']) !== ''
) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $path = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($path, '/admin/') === false && strpos($path, '\\admin\\') === false
        && ($script === 'index.php' || $script === 'pwreset.php')
    ) {
        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('pwreset');
            header('Location: pwreset.php?error=captcha');
            exit;
        }
        $token = isset($_POST['cf-turnstile-response']) ? trim((string) $_POST['cf-turnstile-response']) : '';
        if ($token === '' || !cloudgate_verify($token)) {
            cloudgate_log_failure('pwreset');
            header('Location: pwreset.php?error=captcha');
            exit;
        }
    }
}

/**
 * Register Smarty Function {display_turnstile}
 */
add_hook('ClientAreaPageHooks', 1, function ($vars) {
    return [
        'display_turnstile' => function($params, $smarty) {
            $siteKey = cloudgate_get_site_key();
            if (!$siteKey) return '';
            $theme = cloudgate_get_setting('theme') ?: 'auto';
            return '<div class="cf-turnstile" data-sitekey="' . $siteKey . '" data-theme="' . $theme . '"></div>';
        }
    ];
});


/**
 * Inject Cloudflare Turnstile Script
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (!cloudgate_get_site_key()) return;
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
});

/**
 * Inject Widget into Forms via Footer JS
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $siteKey = cloudgate_get_site_key();
    if (!$siteKey) return;

    $templatefile = $vars['templatefile'];
    $filename = $vars['filename'];

    $theme = cloudgate_get_setting('theme') ?: 'auto';
    
    // CSS to hide default captcha
    $css = '<style>
        .g-recaptcha,
        #google-recaptcha-domainchecker,
        .recaptcha-container,
        #default-captcha-domainchecker,
        .default-captcha,
        #captchaContainer,
        #inputCaptcha,
        #inputCaptchaImage {
            display: none !important;
        }
        /* Keep Turnstile and checkout button containers visible */
        .cf-turnstile,
        .tt-captcha-join-mail {
            display: block !important;
        }
    </style>';

    $jsCode = '';

    $tsAlertJs = cloudgate_js_string('prompt');
    $tsBannerHtml = '<div class="alert alert-danger" style="margin-bottom:20px;">' . htmlspecialchars(cloudgate_text('error'), ENT_QUOTES, 'UTF-8') . '</div>';
    $tsBannerJs = json_encode($tsBannerHtml, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    // Helper to get selector
    $getSelector = function($configName, $defaults) {
        $custom = cloudgate_get_setting($configName);
        if ($custom && trim($custom) !== '') {
            // If custom selector is provided, just assume prepend
            return 'jQuery("' . addslashes($custom) . '").before(\'' . $this->widgetHtml . '\');'; 
        }
        return false;
    };

    // Note: We can't access $widgetHtml or $getSelector easily inside helper without passing classes or global.
    // Let's just do inline logic for simplicity.

    // Login
    if ($templatefile == 'login' && cloudgate_is_enabled('enable_login')) {
        $widgetHtml = cloudgate_widget_html('login');
        $custom = cloudgate_get_setting('custom_login_sel');
        if ($custom) {
            $sel = addslashes($custom);
            $jsCode .= 'jQuery("' . $sel . '").before(\'' . $widgetHtml . '\');';
            $jsCode .= 'jQuery("' . $sel . '").closest("form").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) { e.preventDefault(); alert(' . $tsAlertJs . '); return false; }
            });';
        } else {
            // Default logic including Megatech + WHMCS 8+ routed login (login.php / index.php?rp=.../login/validate)
            $jsCode .= 'if(jQuery(".cloudgate-login-wrap").length) {
                 jQuery(".cloudgate-login-wrap form button[type=\'submit\']").closest("button").before(\'' . $widgetHtml . '\');
            } else {
                 jQuery("form.login-form, form[action*=\'dologin\'], form[action*=\'login/validate\'], form[action*=\'login%2fvalidate\']")
                    .find("button[type=\'submit\'], input[type=\'submit\']").first().closest("div.form-group, div.mb-3, .float-left, .text-center").before(\'' . $widgetHtml . '\');
            }';
        }
        $jsCode .= '
            jQuery("form.login-form, .cloudgate-login-wrap form, form[action*=\'dologin\'], form[action*=\'login/validate\'], form[action*=\'login%2fvalidate\']").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) {
                    e.preventDefault();
                    alert(' . $tsAlertJs . ');
                    return false;
                }
            });
            if (window.location.search.indexOf("error=captcha") !== -1) {
                jQuery(".cloudgate-login-wrap, form.login-form").closest("section, .container, .card, main").first()
                    .prepend(' . $tsBannerJs . ');
            }';
    }

    // Register
    if ($templatefile == 'clientregister' && cloudgate_is_enabled('enable_register')) {
        $widgetHtml = cloudgate_widget_html('register');
        $custom = cloudgate_get_setting('custom_register_sel');
        if ($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
            // WHMCS 8+ Twenty-One: no #btnRegister — form is #frmCheckout with hidden name="register"
            $jsCode .= 'if(jQuery(".cloudgate-register-wrap").length) {
                jQuery(".cloudgate-register-wrap form button[type=\'submit\']").closest("button").before(\'' . $widgetHtml . '\');
            } else {
                var $regBtn = jQuery("#btnRegister");
                if ($regBtn.length) {
                    $regBtn.closest("div.form-group, div.mb-3").before(\'' . $widgetHtml . '\');
                } else {
                    jQuery(\'form:has(input[name="register"][value="true"]), form#frmCheckout\').find("input[type=\'submit\'], button[type=\'submit\']").first()
                        .closest("p.text-center, div.text-center, div.form-group, .card-body").before(\'' . $widgetHtml . '\');
                }
            }';
        }
    }

    /*
     * Password reset: do NOT rely on $templatefile (WHMCS 8–10 / child themes may use different names).
     * If the condition never matched, $jsCode stayed empty and nothing ran — Turnstile never appeared.
     * We detect the standard email step by hidden input name="action" value="reset" (WHMCS core forms).
     */
    if (cloudgate_is_enabled('enable_pwreset')) {
        $widgetHtml = cloudgate_widget_html('pwreset');
        $customPw = cloudgate_get_setting('custom_pwreset_sel');
        if ($customPw && trim($customPw) !== '') {
            $sel = addslashes($customPw);
            $jsCode .= 'jQuery("' . $sel . '").before(\'' . $widgetHtml . '\');';
            $jsCode .= 'jQuery("' . $sel . '").closest("form").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) { e.preventDefault(); alert(' . $tsAlertJs . '); return false; }
            });';
        } else {
            $widgetJson = json_encode($widgetHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $jsCode .= '(function(w){
                var $in = jQuery(\'input[type="hidden"][name="action"][value="reset"]\');
                if (!$in.length) return;
                var $f = $in.closest("form");
                if (!$f.length || $f.find(".cf-turnstile").length) return;
                var $btn = $f.find("button[type=\'submit\'],input[type=\'submit\']").first();
                if ($btn.length) { $btn.before(w); }
            })(' . $widgetJson . ');';
            $jsCode .= '
                jQuery(\'input[type="hidden"][name="action"][value="reset"]\').closest("form").off("submit.cloudgatePw").on("submit.cloudgatePw", function(e) {
                    if (jQuery(this).find(\'input[name="email"]\').length === 0) return;
                    var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                    if (!token) {
                        e.preventDefault();
                        alert(' . $tsAlertJs . ');
                        return false;
                    }
                });
                if (window.location.search.indexOf("error=captcha") !== -1) {
                    var $pwf = jQuery(\'input[type="hidden"][name="action"][value="reset"]\').closest("form");
                    if ($pwf.length) {
                        $pwf.closest("section,.container,.card,main,.login_form,.login-page,.cloudgate-form-wrap").first()
                            .prepend(' . $tsBannerJs . ');
                    }
                }';
        }
    }

    // Support Ticket
    if (($templatefile == 'supportticketsubmit-stepone' || $templatefile == 'supportticketsubmit-steptwo') && cloudgate_is_enabled('enable_ticket')) {
        $widgetHtml = cloudgate_widget_html('ticket');
        $custom = cloudgate_get_setting('custom_ticket_sel');
        if($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
             $jsCode .= 'jQuery("#openTicketSubmit").closest("p, div.form-group").before(\'' . $widgetHtml . '\');';
        }
    }

    // Contact
    if ($templatefile == 'contact' && cloudgate_is_enabled('enable_contact')) {
        $widgetHtml = cloudgate_widget_html('contact');
        $custom = cloudgate_get_setting('custom_contact_sel');
        if($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
             $jsCode .= 'if(jQuery(".cloudgate-contact-form-wrap").length) {
                jQuery(".cloudgate-contact-form-wrap form button[type=\'submit\']").closest(".col-12").before(\'<div class="col-12">' . $widgetHtml . '</div>\');
            } else {
                jQuery("form[action*=\'contact\'] button[type=\'submit\']").closest("p, div.text-center, div.col-12, div.form-group").before(\'' . $widgetHtml . '\');
            }';
        }
        $jsCode .= '
            jQuery("form[action*=\'contact.php\'], .cloudgate-contact-form-wrap form").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) {
                    e.preventDefault();
                    alert(' . $tsAlertJs . ');
                    return false;
                }
            });
            if (window.location.search.indexOf("error=captcha") !== -1) {
                jQuery(".cloudgate-contact-form-wrap, form[action*=\'contact.php\']").closest("section, .container").first()
                    .prepend(' . $tsBannerJs . ');
            }';
    }

    /*
     * Checkout "Existing Customer Login" uses AJAX (POST .../login/cart) — not the login.tpl form.
     * Early login interception still requires cf-turnstile-response whenever enable_login is on.
     * Without this, WHMCS redirects to login.php?error=captcha and jqClient sees parsererror (expects JSON).
     */
    if ((strpos($templatefile, 'checkout') !== false || $filename == 'cart') && cloudgate_is_enabled('enable_login')) {
        $widgetHtml = cloudgate_widget_html('cart');
        $widgetJson = json_encode($widgetHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $jsCode .= '(function(w) {
            var $b = jQuery("#btnExistingLogin");
            if (!$b.length) return;
            var $scope = jQuery("#containerExistingUserSignin");
            if (!$scope.length) { $scope = $b.closest("form").length ? $b.closest("form") : $b.parent(); }
            if ($scope.find(".cf-turnstile").length) return;
            $b.before(w);
        })(' . $widgetJson . ');';
        $jsCode .= '
        function cloudgateTurnstileCheckoutCartToken() {
            var $scope = jQuery("#containerExistingUserSignin");
            if (!$scope.length) { $scope = jQuery("#btnExistingLogin").parent(); }
            var $t = $scope.find("[name=\'cf-turnstile-response\']");
            return ($t.length && $t.val()) ? $t.val() : "";
        }
        jQuery.ajaxPrefilter(function(options) {
            var url = String(options.url || "");
            if (url.indexOf("login/cart") === -1) return;
            var token = cloudgateTurnstileCheckoutCartToken();
            if (jQuery.isPlainObject(options.data)) {
                options.data["cf-turnstile-response"] = token;
            } else if (typeof options.data === "string") {
                options.data += (options.data.length ? "&" : "") + "cf-turnstile-response=" + encodeURIComponent(token);
            }
        });
        document.addEventListener("click", function(e) {
            if (!e.target || !e.target.closest || !e.target.closest("#btnExistingLogin")) return;
            if (!cloudgateTurnstileCheckoutCartToken()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                alert(' . $tsAlertJs . ');
            }
        }, true);
        jQuery(document).ajaxError(function(event, jqXHR, ajaxSettings) {
            var url = String(ajaxSettings.url || "");
            if (url.indexOf("login/cart") === -1) return;
            if (typeof window.turnstile === "undefined" || typeof turnstile.reset !== "function") return;
            var el = document.querySelector("#containerExistingUserSignin .cf-turnstile");
            if (el) { try { turnstile.reset(el); } catch (err) {} }
        })
        
        jQuery(document).ajaxSuccess(function(event, jqXHR, ajaxSettings, data) {
            var url = String(ajaxSettings.url || "");
            if (url.indexOf("login/cart") === -1) return;
            var result = (data && data.result) ? data.result : null;
            if (result && result !== "success") {
                if (typeof window.turnstile !== "undefined" && typeof turnstile.reset === "function") {
                    var el = document.querySelector("#containerExistingUserSignin .cf-turnstile");
                    if (el) { try { turnstile.reset(el); } catch(err) {} }
                }
            }
        });';
    }

    // Shopping Cart / Checkout — complete order (separate from AJAX login above)
    if ((strpos($templatefile, 'checkout') !== false || $filename == 'cart') && cloudgate_is_enabled('enable_cart')) {
        $widgetHtml = cloudgate_widget_html('cart');
        $custom = cloudgate_get_setting('custom_cart_sel');
        if($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
             $jsCode .= 'jQuery("#btnCompleteOrder").closest("div").before(\'' . $widgetHtml . '\');';
        }
    }

    if ($jsCode) {
        return $css . '<script>jQuery(document).ready(function() { ' . $jsCode . ' });</script>';
    }
});

/**
 * Validation Hooks
 */

// Login Validation
add_hook('UserLoginVerification', 1, function ($vars) {
    if (cloudgate_is_enabled('enable_login')) {
        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('login');
            return cloudgate_text('error');
        }
        $token = isset($_POST['cf-turnstile-response']) ? trim((string) $_POST['cf-turnstile-response']) : '';
        if ($token === '' || !cloudgate_verify($token)) {
            cloudgate_log_failure('login');
            return cloudgate_text('error');
        }
    }
});

// Registration Validation
add_hook('ClientDetailsValidation', 1, function ($vars) {
    if (!isset($_SESSION['uid']) && cloudgate_is_enabled('enable_register')) {
        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('register');
            return [cloudgate_text('error')];
        }
        if (!isset($_POST['cf-turnstile-response']) || !cloudgate_verify($_POST['cf-turnstile-response'])) {
            cloudgate_log_failure('register');
            return [cloudgate_text('error')];
        }
    }
});

// Shopping Cart Validation
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (cloudgate_is_enabled('enable_cart')) {
        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('cart');
            return cloudgate_text('error');
        }
        if (!isset($_POST['cf-turnstile-response']) || !cloudgate_verify($_POST['cf-turnstile-response'])) {
            cloudgate_log_failure('cart');
            return cloudgate_text('error');
        }
    }
});

// Ticket Validation
add_hook('TicketOpenValidation', 1, function ($vars) {
    if (cloudgate_is_enabled('enable_ticket')) {
        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('ticket');
            return cloudgate_text('error');
        }
        if (!isset($_POST['cf-turnstile-response']) || !cloudgate_verify($_POST['cf-turnstile-response'])) {
            cloudgate_log_failure('ticket');
            return cloudgate_text('error');
        }
    }
});

// Contact Form Validation
add_hook('ClientAreaPageContact', 1, function ($vars) {
    if (cloudgate_is_enabled('enable_contact') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['action']) || $_POST['action'] !== 'send') return;

        $ip = cloudgate_get_ip();
        if (cloudgate_is_rate_limited($ip)) {
            cloudgate_log_failure('contact');
            header("Location: contact.php?error=captcha");
            exit;
        }
        if (!isset($_POST['cf-turnstile-response']) || !cloudgate_verify($_POST['cf-turnstile-response'])) {
            cloudgate_log_failure('contact');
            header("Location: contact.php?error=captcha");
            exit;
        }
    }
});

