<?php

declare(strict_types=1);

/**
 * DNS guard used by the deadlist.
 *
 * Persoc keeps the IP-level nftables deadlist as a second line of defence, but
 * hostnames are also answered locally with NXDOMAIN. Student DNS traffic is
 * transparently redirected to the local dnsmasq instance, so using another
 * classic DNS server does not bypass the deadlist. Managed browser policies
 * disable DNS-over-HTTPS for Firefox/Chromium/Chrome.
 */

function persoc_dns_settings(): array
{
    global $Configuration;

    $dns = is_array($Configuration["DNS"] ?? null) ? $Configuration["DNS"] : [];
    return [
        "Enabled" => (bool)($dns["Enabled"] ?? false),
        "Port" => max(1024, min(65535, (int)($dns["Port"] ?? 53535))),
        "ConfigFile" => trim((string)($dns["ConfigFile"] ?? "/etc/persoc/dnsmasq.conf")),
        "PidFile" => trim((string)($dns["PidFile"] ?? "/run/persoc-dnsmasq.pid")),
        "User" => trim((string)($dns["User"] ?? "nobody")),
        "Group" => trim((string)($dns["Group"] ?? "nogroup")),
        "BrowserPolicies" => (bool)($dns["BrowserPolicies"] ?? true),
        "FirefoxPolicy" => trim((string)($dns["FirefoxPolicy"] ?? "/etc/firefox/policies/policies.json")),
        "ChromiumPolicy" => trim((string)($dns["ChromiumPolicy"] ?? "/etc/chromium/policies/managed/persoc.json")),
        "ChromePolicy" => trim((string)($dns["ChromePolicy"] ?? "/etc/opt/chrome/policies/managed/persoc.json")),
    ];
}

function persoc_dns_enabled(): bool
{
    return ((bool)(persoc_dns_settings()["Enabled"] ?? false));
}

function persoc_dns_normalize_hostname($value): ?string
{
    $value = strtolower(rtrim(trim((string)$value), "."));
    if ($value === "" || strlen($value) > 253)
        return null;
    if (filter_var($value, FILTER_VALIDATE_IP) !== false)
        return null;
    if (function_exists("idn_to_ascii"))
    {
        $flags = defined("IDNA_DEFAULT") ? IDNA_DEFAULT : 0;
        $variant = defined("INTL_IDNA_VARIANT_UTS46") ? INTL_IDNA_VARIANT_UTS46 : 0;
        $ascii = @idn_to_ascii($value, $flags, $variant);
        if (is_string($ascii) && $ascii !== "")
            $value = strtolower($ascii);
    }
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $value))
        return null;
    return $value;
}

function persoc_dns_deadlist_domains(string $csvPath): array
{
    $raw = @file($csvPath, FILE_IGNORE_NEW_LINES);
    if ($raw === false)
        return [];

    $domains = [];
    foreach ($raw as $line)
    {
        $line = trim($line);
        if ($line === "" || str_starts_with($line, "#"))
            continue;
        $cols = str_getcsv($line);
        if (!$cols || !isset($cols[0]))
            continue;
        $domain = persoc_dns_normalize_hostname($cols[0]);
        if ($domain !== null)
            $domains[$domain] = true;
    }
    $out = array_keys($domains);
    sort($out, SORT_STRING);
    return $out;
}

function persoc_dns_resolve_uid(string $user): ?int
{
    if ($user === "")
        return null;
    if (function_exists("posix_getpwnam"))
    {
        $pw = @posix_getpwnam($user);
        if (is_array($pw) && isset($pw["uid"]))
            return (int)$pw["uid"];
    }
    $raw = @shell_exec("id -u " . escapeshellarg($user) . " 2>/dev/null");
    if (is_string($raw) && preg_match('/^\s*(\d+)\s*$/D', $raw, $m))
        return (int)$m[1];
    return null;
}

function persoc_dns_atomic_write(string $path, string $content, int $mode = 0644): bool
{
    $current = @file_get_contents($path);
    if (is_string($current) && $current === $content)
        return true;
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir))
        return false;
    $tmp = $path . ".tmp." . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $content) === false)
        return false;
    @chmod($tmp, $mode);
    if (!@rename($tmp, $path))
    {
        @unlink($tmp);
        return false;
    }
    return true;
}

function persoc_dns_browser_policies(array $settings): array
{
    if (empty($settings["BrowserPolicies"]))
        return ["ok" => true, "written" => 0];

    $errors = [];
    $written = 0;

    $firefoxPath = (string)$settings["FirefoxPolicy"];
    if ($firefoxPath !== "")
    {
        $current = [];
        if (is_file($firefoxPath))
        {
            $raw = @file_get_contents($firefoxPath);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if ($decoded === null && trim((string)$raw) !== "")
                $errors[] = "invalid existing Firefox policy JSON: " . $firefoxPath;
            else if (is_array($decoded))
                $current = $decoded;
        }
        if (!count($errors))
        {
            if (!isset($current["policies"]) || !is_array($current["policies"]))
                $current["policies"] = [];
            $current["policies"]["DNSOverHTTPS"] = ["Enabled" => false, "Locked" => true];
            $json = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            if (!persoc_dns_atomic_write($firefoxPath, $json))
                $errors[] = "cannot write Firefox policy: " . $firefoxPath;
            else
                $written++;
        }
    }

    $chromium = json_encode(["DnsOverHttpsMode" => "off"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    foreach (["ChromiumPolicy", "ChromePolicy"] as $key)
    {
        $path = (string)$settings[$key];
        if ($path === "")
            continue;
        if (!persoc_dns_atomic_write($path, $chromium))
            $errors[] = "cannot write browser policy: " . $path;
        else
            $written++;
    }

    return ["ok" => !count($errors), "written" => $written, "errors" => $errors];
}

function persoc_dns_config_text(array $settings, array $domains): string
{
    $lines = [
        "# Generated by Persoc. Do not edit by hand.",
        "port=" . (int)$settings["Port"],
        "listen-address=127.0.0.1",
        "listen-address=::1",
        "bind-interfaces",
        "domain-needed",
        "bogus-priv",
        "cache-size=1000",
        "user=" . $settings["User"],
        "group=" . $settings["Group"],
        "pid-file=" . $settings["PidFile"],
        "",
        "# Deadlist: NXDOMAIN for the domain and all of its subdomains.",
    ];
    foreach ($domains as $domain)
        $lines[] = "address=/" . $domain . "/";
    return implode("\n", $lines) . "\n";
}

function persoc_dns_pid(string $pidFile): ?int
{
    $raw = @file_get_contents($pidFile);
    if (!is_string($raw) || !preg_match('/^\s*(\d+)\s*$/D', $raw, $m))
        return null;
    $pid = (int)$m[1];
    return $pid > 1 ? $pid : null;
}

function persoc_dns_process_running(string $pidFile): bool
{
    $pid = persoc_dns_pid($pidFile);
    if ($pid === null)
        return false;
    if (function_exists("posix_kill"))
        return @posix_kill($pid, 0);
    @exec("kill -0 " . $pid . " 2>/dev/null", $out, $status);
    return $status === 0;
}

function persoc_dns_stop(string $pidFile): void
{
    $pid = persoc_dns_pid($pidFile);
    if ($pid === null)
        return;
    if (function_exists("posix_kill"))
        @posix_kill($pid, 15);
    else
        @exec("kill " . $pid . " 2>/dev/null");
    for ($i = 0; $i < 20 && persoc_dns_process_running($pidFile); $i++)
        usleep(50000);
    @unlink($pidFile);
}

function persoc_dns_start(array $settings): array
{
    $binary = trim((string)@shell_exec("command -v dnsmasq 2>/dev/null"));
    if ($binary === "")
        return ["ok" => false, "error" => "dnsmasq not found (install dnsmasq-base)"];

    $test = escapeshellarg($binary) . " --test --conf-file=" . escapeshellarg($settings["ConfigFile"]);
    @exec($test . " 2>&1", $testOut, $testStatus);
    if ($testStatus !== 0)
        return ["ok" => false, "error" => "dnsmasq configuration rejected: " . trim(implode(" ", $testOut))];

    @unlink($settings["PidFile"]);
    $cmd = escapeshellarg($binary) .
        " --conf-file=" . escapeshellarg($settings["ConfigFile"]) .
        " --pid-file=" . escapeshellarg($settings["PidFile"]);
    @exec($cmd . " 2>&1", $out, $status);
    if ($status !== 0 || !persoc_dns_process_running($settings["PidFile"]))
        return ["ok" => false, "error" => "cannot start dnsmasq: " . trim(implode(" ", $out))];
    return ["ok" => true];
}

function persoc_dns_remove_redirect(): void
{
    @system("nft delete table inet persoc_dns 2>/dev/null || true");
}

function persoc_dns_program_redirect(array $settings, int $dnsUid): array
{
    $port = (int)$settings["Port"];
    $cmds = [
        "nft add table inet persoc_dns 2>/dev/null || true",
        "nft add chain inet persoc_dns output '{ type nat hook output priority dstnat; policy accept; }' 2>/dev/null || true",
        "nft flush chain inet persoc_dns output",
        "nft add rule inet persoc_dns output meta skuid != " . $dnsUid . " udp dport 53 redirect to :" . $port,
        "nft add rule inet persoc_dns output meta skuid != " . $dnsUid . " tcp dport 53 redirect to :" . $port,
    ];
    $errors = [];
    foreach ($cmds as $cmd)
    {
        $ret = 0;
        @system($cmd, $ret);
        if ($ret !== 0)
            $errors[] = $cmd;
    }
    return ["ok" => !count($errors), "errors" => $errors];
}

function persoc_dns_apply_deadlist(string $csvPath): array
{
    $settings = persoc_dns_settings();
    if (empty($settings["Enabled"]))
    {
        persoc_dns_remove_redirect();
        return ["ok" => true, "enabled" => false, "domains" => 0];
    }

    $domains = persoc_dns_deadlist_domains($csvPath);
    $dnsUid = persoc_dns_resolve_uid((string)$settings["User"]);
    if ($dnsUid === null)
        return ["ok" => false, "error" => "cannot resolve dnsmasq user: " . $settings["User"]];

    $config = persoc_dns_config_text($settings, $domains);
    $old = @file_get_contents($settings["ConfigFile"]);
    $changed = !is_string($old) || $old !== $config;
    if ($changed && !persoc_dns_atomic_write($settings["ConfigFile"], $config))
        return ["ok" => false, "error" => "cannot write dnsmasq configuration: " . $settings["ConfigFile"]];

    $browser = persoc_dns_browser_policies($settings);
    $warnings = [];
    if (!($browser["ok"] ?? false))
    {
        $warnings = $browser["errors"] ?? ["browser policy error"];
        if (function_exists("persoc_log"))
            persoc_log("DNS guard browser policy warning: " . implode("; ", $warnings));
    }

    if ($changed && persoc_dns_process_running($settings["PidFile"]))
        persoc_dns_stop($settings["PidFile"]);
    if (!persoc_dns_process_running($settings["PidFile"]))
    {
        $started = persoc_dns_start($settings);
        if (!($started["ok"] ?? false))
        {
            // Never leave student DNS redirected to a dead local port. The IP
            // nftables deadlist remains active even when the DNS guard cannot
            // start.
            persoc_dns_remove_redirect();
            return $started + ["domains" => count($domains)];
        }
    }

    $redirect = persoc_dns_program_redirect($settings, $dnsUid);
    if (!($redirect["ok"] ?? false))
        return ["ok" => false, "error" => "cannot program DNS redirect: " . implode(" | ", $redirect["errors"] ?? [])];

    return [
        "ok" => true,
        "enabled" => true,
        "domains" => count($domains),
        "port" => (int)$settings["Port"],
        "dns_uid" => $dnsUid,
        "restarted" => $changed,
        "browser_policies" => (int)($browser["written"] ?? 0),
        "warnings" => $warnings,
    ];
}
