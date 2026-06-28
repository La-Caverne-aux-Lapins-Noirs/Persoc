<?php

function persoc_configuration_shell_output(string $cmd): string
{
    $out = @shell_exec($cmd);
    return is_string($out) ? $out : "";
}

function persoc_configuration_detect_network_identity(): array
{
    $route = persoc_configuration_shell_output("ip route get 8.8.8.8 2>/dev/null");

    $iface = "";
    $ip = "";
    $mac = "";

    if (preg_match('/\bdev\s+([a-zA-Z0-9_.:-]+)\b/', $route, $m) === 1)
        $iface = $m[1];
    if (preg_match('/\bsrc\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\b/', $route, $m) === 1)
        $ip = $m[1];

    if ($iface !== "")
    {
        if ($ip === "")
        {
            $addr = persoc_configuration_shell_output("ip -4 addr show dev " . escapeshellarg($iface) . " 2>/dev/null");
            if (preg_match('/\binet\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\//', $addr, $m) === 1)
                $ip = $m[1];
        }

        $link = persoc_configuration_shell_output("ip link show dev " . escapeshellarg($iface) . " 2>/dev/null");
        if (preg_match('/\blink\/ether\s+([0-9a-fA-F:]{17})\b/', $link, $m) === 1)
            $mac = strtolower($m[1]);
        if ($mac === "")
        {
            $legacy = persoc_configuration_shell_output("cat " . escapeshellarg("/sys/class/net/" . $iface . "/address") . " 2>/dev/null");
            if (preg_match('/\b([0-9a-fA-F:]{17})\b/', $legacy, $m) === 1)
                $mac = strtolower($m[1]);
        }
    }

    return [
        "Interface" => $iface,
        "IP" => $ip,
        "Mac" => $mac,
    ];
}

function load_configuration(string $conf_file = ""): array
{
    if ($conf_file === "")
    {
        if (file_exists("./persoc.dab"))
            $conf_file = "./persoc.dab";
        else if (isset($_SERVER["HOME"]) && file_exists($_SERVER["HOME"] . "/persoc.dab"))
            $conf_file = $_SERVER["HOME"] . "/persoc.dab";
        else
            $conf_file = "/etc/persoc/persoc.dab";
    }

    $conf_file = escapeshellarg($conf_file);
    $raw = shell_exec("mergeconf -i " . $conf_file . " -of .json --resolve 2>/dev/null");
    $conf = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($conf))
        throw new RuntimeException("cannot load configuration via mergeconf");

    // Mandatory fields
    foreach (["Distrans"] as $k)
    {
        if (!array_key_exists($k, $conf))
            throw new RuntimeException("missing configuration field: " . $k);
    }
    if (!is_string($conf["Distrans"]) || trim($conf["Distrans"]) === "")
        throw new RuntimeException("Distrans must be a non-empty string");

    // Normalize allowlists
    foreach (["Custom"] as $k)
    {
        if (!array_key_exists($k, $conf))
        {
            $conf[$k] = [];
            continue;
        }

        if (is_string($conf[$k]))
            $conf[$k] = [trim($conf[$k])];
        if (!is_array($conf[$k]))
            throw new RuntimeException("$k must be string or array");

        $out = [];
        foreach ($conf[$k] as $v)
        {
            if (!is_string($v))
                continue;
            $v = trim($v);
            if ($v !== "")
                $out[] = $v;
        }
        $conf[$k] = array_values(array_unique($out));
    }

    // Optional: deadlist file
    if (!isset($conf["Deadlist"]) || !is_string($conf["Deadlist"]) || trim($conf["Deadlist"]) === "")
        $conf["Deadlist"] = "/etc/persoc/deadlist.csv";

    // Optional: log file
    if (!isset($conf["LogFile"]) || !is_string($conf["LogFile"]) || trim($conf["LogFile"]) === "")
        $conf["LogFile"] = "/var/log/persoc/persoc.log";

    // Intervals defaults are centralized here
    if (!isset($conf["Intervals"]) || !is_array($conf["Intervals"]))
        $conf["Intervals"] = [];

    if (!isset($conf["Intervals"]["Tick"]))      $conf["Intervals"]["Tick"] = 1;
    if (!isset($conf["Intervals"]["Activity"]))  $conf["Intervals"]["Activity"] = 5;
    if (!isset($conf["Intervals"]["Intruders"])) $conf["Intervals"]["Intruders"] = 5;
    if (!isset($conf["Intervals"]["Deadlist"]))  $conf["Intervals"]["Deadlist"] = 0;

    $conf["Intervals"]["Tick"]      = max(1, (int)$conf["Intervals"]["Tick"]);
    $conf["Intervals"]["Activity"]  = max(1, (int)$conf["Intervals"]["Activity"]);
    $conf["Intervals"]["Intruders"] = max(1, (int)$conf["Intervals"]["Intruders"]);
    $conf["Intervals"]["Deadlist"]  = max(0, (int)$conf["Intervals"]["Deadlist"]);

    // Optional: refined activity scoring. These values are also guarded at
    // runtime in log_activity.php so old configurations remain valid.
    if (!isset($conf["Activity"]) || !is_array($conf["Activity"]))
        $conf["Activity"] = [];

    $activity_list = function($value, array $default): array {
        if ($value === null)
            return $default;
        if (is_string($value))
            $value = [$value];
        if (!is_array($value))
            return $default;

        $out = [];
        foreach ($value as $v)
        {
            if (!is_string($v))
                continue;
            $v = trim($v);
            if ($v !== "")
                $out[] = $v;
        }
        return array_values(array_unique($out));
    };

    $a = &$conf["Activity"];
    if (!isset($a["Enabled"])) $a["Enabled"] = true;
    if (!isset($a["Debug"])) $a["Debug"] = false;
    $a["TTYRecentSeconds"] = max(1, (int)($a["TTYRecentSeconds"] ?? 120));
    $a["IdlePenaltySeconds"] = max(1, (int)($a["IdlePenaltySeconds"] ?? 1800));
    $a["RecentFileSeconds"] = max(1, (int)($a["RecentFileSeconds"] ?? 900));
    $a["FilesystemScanEvery"] = max(1, (int)($a["FilesystemScanEvery"] ?? 30));
    $a["MaxScanDepth"] = max(0, (int)($a["MaxScanDepth"] ?? 6));
    $a["MaxScanFiles"] = max(1, (int)($a["MaxScanFiles"] ?? 5000));
    $a["SuspiciousAfterSeconds"] = max(1, (int)($a["SuspiciousAfterSeconds"] ?? 900));
    if (!isset($a["HomePattern"]) || !is_string($a["HomePattern"]) || trim($a["HomePattern"]) === "")
        $a["HomePattern"] = "/home/users/%u";
    $a["WorkPaths"] = $activity_list($a["WorkPaths"] ?? null, ["work", "Work", "rendu", "Rendu", "projects", "Projects", "Projets"]);
    $a["SourceExtensions"] = $activity_list($a["SourceExtensions"] ?? null, ["c", "h", "cpp", "hpp", "cc", "cxx", "hh", "py", "php", "js", "ts", "java", "cs", "sh", "dab", "md", "tex"]);
    $a["EditorCommands"] = $activity_list($a["EditorCommands"] ?? null, ["emacs", "vim", "vi", "nano", "micro", "code", "nvim"]);
    $a["WorkCommands"] = $activity_list($a["WorkCommands"] ?? null, ["gcc", "g++", "clang", "clang++", "make", "cmake", "ninja", "gdb", "valgrind", "python", "python3", "php", "node", "npm", "git"]);
    unset($a);

    $identity = persoc_configuration_detect_network_identity();
    $conf["Interface"] = $identity["Interface"];
    $conf["IP"] = $identity["IP"];
    $conf["Mac"] = $identity["Mac"];

    return $conf;
}
