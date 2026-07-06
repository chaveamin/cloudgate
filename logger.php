<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function cloudgate_log_failure($page)
{
    try {
        Capsule::table('mod_cloudgate_logs')->insert([
            'page'       => $page,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Exception $e) {

    }
}

function cloudgate_get_ip()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function cloudgate_get_failure_count($ip, $minutes = 5)
{
    try {
        return (int) Capsule::table('mod_cloudgate_logs')
            ->where('ip', $ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$minutes} minutes")))
            ->count();
    } catch (\Exception $e) {
        return 0;
    }
}

function cloudgate_is_rate_limited($ip = null)
{
    if (cloudgate_get_setting('rate_limit_enabled') !== 'on') {
        return false;
    }

    $ip = $ip ?: cloudgate_get_ip();
    $maxAttempts = (int) (cloudgate_get_setting('rate_limit_max') ?: 5);
    $windowMinutes = (int) (cloudgate_get_setting('rate_limit_window') ?: 5);

    $recentFailures = cloudgate_get_failure_count($ip, $windowMinutes);
    return $recentFailures >= $maxAttempts;
}

function cloudgate_parse_ip_list($raw)
{
    $list = [];
    foreach (explode(',', $raw ?? '') as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        $list[] = $entry;
    }
    return $list;
}

function cloudgate_ip_matches_cidr($ip, $cidr)
{
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }

    list($subnet, $mask) = explode('/', $cidr);
    $mask = (int) $mask;

    if ($mask < 0 || $mask > 32) return false;

    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);

    if ($ipLong === false || $subnetLong === false) return false;

    $maskLong = -1 << (32 - $mask);
    return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
}

function cloudgate_ip_in_list($ip, $listRaw)
{
    $list = cloudgate_parse_ip_list($listRaw);
    foreach ($list as $entry) {
        if (cloudgate_ip_matches_cidr($ip, $entry)) {
            return true;
        }
    }
    return false;
}

function cloudgate_is_whitelisted($ip = null)
{
    $ip = $ip ?: cloudgate_get_ip();
    $listRaw = cloudgate_get_setting('ip_whitelist');
    return cloudgate_ip_in_list($ip, $listRaw);
}

function cloudgate_is_blacklisted($ip = null)
{
    $ip = $ip ?: cloudgate_get_ip();
    $listRaw = cloudgate_get_setting('ip_blacklist');
    return cloudgate_ip_in_list($ip, $listRaw);
}