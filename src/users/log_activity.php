<?php
declare(strict_types=1);

/*
 * Persoc -> Distrans: send activity packet for log_activity command.
 *
 * Requires:
 * - send_data() + hand_packet() available (e.g. require src/hand/send_data.php)
 * - global $Configuration["Distrans"] set to the IH host
 */

function persoc_parse_duration(string $idle): int
{
    $idle = trim($idle);
    if ($idle === '.' || $idle === '')
        return 0;

    // "123s" or "123.45s"
    if (preg_match('/^([0-9]+)(\.[0-9]+)?s$/', $idle, $m))
        return (int)$m[1];

    // "MM:SS" (w idle often), or "HH:MM" (sometimes)
    if (preg_match('/^([0-9]+):([0-9]+)$/', $idle, $m))
        return ((int)$m[1] * 60 + (int)$m[2]);

    // "HH:MM:SS"
    if (preg_match('/^([0-9]+):([0-9]+):([0-9]+)$/', $idle, $m))
        return ((int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3]);

    // "DD-HH:MM:SS" (ps etime can be like 1-02:03:04)
    if (preg_match('/^([0-9]+)-([0-9]+):([0-9]+):([0-9]+)$/', $idle, $m))
        return ((int)$m[1] * 86400 + (int)$m[2] * 3600 + (int)$m[3] * 60 + (int)$m[4]);

    return 0;
}

function persoc_is_user_lock(string $user): int
{
    $user = trim($user);
    if ($user === "")
        return 0;

    // Strict-ish: parse ps output, match xtrlock-pam command
    $lst = @shell_exec("ps -eo user:256,pid,etime,cmd 2>/dev/null | grep xtrlock-pam | grep " . escapeshellarg($user) . " | grep -v grep | tr -s ' '");
    if (!is_string($lst) || trim($lst) === "")
        return 0;

    foreach (explode("\n", trim($lst)) as $l)
    {
        $l = trim($l);
        if ($l === "") continue;

        $parts = explode(" ", $l);
        // expected: user pid etime cmd...
        if (count($parts) >= 4 && preg_match('/^xtrlock-pam(\s|$)/', $parts[3]))
            return persoc_parse_duration($parts[2]);
    }
    return 0;
}

function persoc_activity_now(): int
{
    $v = getenv("PERSOC_NOW");
    if (is_string($v) && preg_match('/^\d+$/', $v) === 1)
        return (int)$v;
    return time();
}

function persoc_activity_bool($value, bool $default): bool
{
    if (is_bool($value))
        return $value;
    if (is_int($value))
        return $value !== 0;
    if (is_string($value))
    {
        $v = strtolower(trim($value));
        if (in_array($v, ["1", "true", "yes", "on"], true))
            return true;
        if (in_array($v, ["0", "false", "no", "off"], true))
            return false;
    }
    return $default;
}

function persoc_activity_list($value, array $default): array
{
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
}

function persoc_activity_configuration(): array
{
    global $Configuration;

    $a = [];
    if (isset($Configuration) && is_array($Configuration) && isset($Configuration["Activity"]) && is_array($Configuration["Activity"]))
        $a = $Configuration["Activity"];

    $cfg = [
        "Enabled" => persoc_activity_bool($a["Enabled"] ?? true, true),
        "Debug" => persoc_activity_bool($a["Debug"] ?? false, false),
        "TTYRecentSeconds" => max(1, (int)($a["TTYRecentSeconds"] ?? 120)),
        "IdlePenaltySeconds" => max(1, (int)($a["IdlePenaltySeconds"] ?? 1800)),
        "RecentFileSeconds" => max(1, (int)($a["RecentFileSeconds"] ?? 900)),
        "FilesystemScanEvery" => max(1, (int)($a["FilesystemScanEvery"] ?? 30)),
        "MaxScanDepth" => max(0, (int)($a["MaxScanDepth"] ?? 6)),
        "MaxScanFiles" => max(1, (int)($a["MaxScanFiles"] ?? 5000)),
        "SuspiciousAfterSeconds" => max(1, (int)($a["SuspiciousAfterSeconds"] ?? 900)),
        "HomePattern" => is_string($a["HomePattern"] ?? null) && trim((string)$a["HomePattern"]) !== "" ? trim((string)$a["HomePattern"]) : "/home/users/%u",
        "WorkPaths" => persoc_activity_list($a["WorkPaths"] ?? null, ["work", "Work", "rendu", "Rendu", "projects", "Projects", "Projets"]),
        "SourceExtensions" => persoc_activity_list($a["SourceExtensions"] ?? null, ["c", "h", "cpp", "hpp", "cc", "cxx", "hh", "py", "php", "js", "ts", "java", "cs", "sh", "dab", "md", "tex"]),
        "EditorCommands" => persoc_activity_list($a["EditorCommands"] ?? null, ["emacs", "vim", "vi", "nano", "micro", "code", "nvim"]),
        "WorkCommands" => persoc_activity_list($a["WorkCommands"] ?? null, ["gcc", "g++", "clang", "clang++", "make", "cmake", "ninja", "gdb", "valgrind", "python", "python3", "php", "node", "npm", "git"]),
    ];

    $cfg["SourceExtensions"] = array_values(array_unique(array_map(fn($x) => strtolower(ltrim($x, ".")), $cfg["SourceExtensions"])));
    return $cfg;
}

function persoc_activity_user_home(string $username, array $cfg): string
{
    $username = trim($username);
    if ($username === "")
        return "";

    if (function_exists("posix_getpwnam"))
    {
        $pw = @posix_getpwnam($username);
        if (is_array($pw) && isset($pw["dir"]) && is_string($pw["dir"]) && $pw["dir"] !== "")
            return $pw["dir"];
    }

    $home = str_replace("%u", $username, (string)$cfg["HomePattern"]);
    if ($home !== "" && $home[0] !== "/")
        $home = "/" . $home;
    return $home;
}

function persoc_activity_is_ignored_path(string $path): bool
{
    $path = str_replace("\\", "/", $path);
    $base = basename($path);

    if ($base === "" || $base === "." || $base === "..")
        return true;

    // Editors/autosaves/temporary files. They may prove a program is running,
    // but they are too weak to count as real work by themselves.
    if (preg_match('/(^#.*#$)|(^\.#)|(~$)|(\.sw[opx]$)|(\.tmp$)|(\.temp$)/', $base) === 1)
        return true;

    // Usual noisy or generated trees.
    if (preg_match('#/(\.cache|\.config|\.local|\.emacs\.d|\.mozilla|\.vscode|node_modules|vendor|__pycache__|\.git|build|cmake-build-[^/]+)(/|$)#', $path) === 1)
        return true;

    return false;
}

function persoc_activity_is_source_file(string $path, array $cfg): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === "")
        return false;
    return in_array($ext, $cfg["SourceExtensions"], true);
}

function persoc_activity_scan_dir(string $dir, int $depth, array $cfg, int $now, array &$stats, int &$seen): void
{
    if ($seen >= (int)$cfg["MaxScanFiles"] || $depth < 0)
        return;

    $items = @scandir($dir);
    if (!is_array($items))
        return;

    foreach ($items as $item)
    {
        if ($seen >= (int)$cfg["MaxScanFiles"])
            return;
        if ($item === "." || $item === "..")
            continue;

        $path = $dir . "/" . $item;
        if (persoc_activity_is_ignored_path($path))
            continue;

        if (is_dir($path) && !is_link($path))
        {
            persoc_activity_scan_dir($path, $depth - 1, $cfg, $now, $stats, $seen);
            continue;
        }

        if (!is_file($path))
            continue;

        $seen++;
        $mtime = @filemtime($path);
        if (!is_int($mtime))
            continue;

        if ($mtime < $now - (int)$cfg["RecentFileSeconds"])
            continue;

        $stats["count"]++;
        $stats["last_mtime"] = max((int)$stats["last_mtime"], $mtime);
        if (persoc_activity_is_source_file($path, $cfg))
            $stats["source_count"]++;
        if (count($stats["samples"]) < 5)
            $stats["samples"][] = $path;
    }
}

function persoc_activity_recent_files(string $username, int $now, array $cfg): array
{
    static $cache = [];

    $key = $username;
    if (isset($cache[$key]) && is_array($cache[$key]) && $now - (int)$cache[$key]["checked_at"] < (int)$cfg["FilesystemScanEvery"])
        return $cache[$key]["stats"];

    $stats = [
        "count" => 0,
        "source_count" => 0,
        "last_mtime" => 0,
        "samples" => [],
    ];

    $home = persoc_activity_user_home($username, $cfg);
    if ($home !== "")
    {
        $dirs = [];
        foreach ($cfg["WorkPaths"] as $p)
        {
            if (!is_string($p) || trim($p) === "")
                continue;
            $p = trim($p);
            $dir = ($p[0] === "/") ? str_replace("%u", $username, $p) : rtrim($home, "/") . "/" . $p;
            if (is_dir($dir))
                $dirs[] = $dir;
        }

        $seen = 0;
        foreach (array_values(array_unique($dirs)) as $dir)
            persoc_activity_scan_dir($dir, (int)$cfg["MaxScanDepth"], $cfg, $now, $stats, $seen);
    }

    $cache[$key] = ["checked_at" => $now, "stats" => $stats];
    return $stats;
}

function persoc_tty_foreground_process(string $tty): array
{
    $tty = trim($tty);
    if ($tty === "")
        return [];

    // Keep only conventional tty names. This avoids command injection and also
    // avoids invoking ps on strange w output.
    if (preg_match('#^(pts/[0-9]+|tty[0-9]+)$#', $tty) !== 1)
        return [];

    $out = @shell_exec("ps -t " . escapeshellarg($tty) . " -o pid=,ppid=,pgid=,tpgid=,stat=,comm=,args= 2>/dev/null");
    if (!is_string($out) || trim($out) === "")
        return [];

    $fallback = [];
    foreach (explode("\n", trim($out)) as $line)
    {
        $line = trim($line);
        if ($line === "")
            continue;

        $parts = preg_split('/\s+/', $line, 7);
        if (!$parts || count($parts) < 6)
            continue;

        $pid = (int)$parts[0];
        $pgid = (int)$parts[2];
        $tpgid = (int)$parts[3];
        $stat = (string)$parts[4];
        $comm = (string)$parts[5];
        $args = (string)($parts[6] ?? $comm);

        $row = [
            "pid" => $pid,
            "comm" => $comm,
            "args" => $args,
            "stat" => $stat,
        ];

        if ($pid > 0)
            $fallback = $row;
        if ($tpgid > 0 && $pgid === $tpgid)
            return $row;
    }

    return $fallback;
}

function persoc_activity_command_class(string $comm, array $cfg): string
{
    $comm = strtolower(basename(trim($comm)));
    if ($comm === "")
        return "";

    foreach ($cfg["EditorCommands"] as $editor)
        if ($comm === strtolower((string)$editor))
            return "editor";

    foreach ($cfg["WorkCommands"] as $cmd)
        if ($comm === strtolower((string)$cmd))
            return "work";

    return "other";
}

function persoc_activity_history(string $username, bool $ttyRecent, bool $hasStrongSignal, int $now, array $cfg): array
{
    static $history = [];

    if (!isset($history[$username]))
        $history[$username] = [
            "tty_only_since" => 0,
            "samples" => 0,
        ];

    if ($ttyRecent && !$hasStrongSignal)
    {
        if ((int)$history[$username]["tty_only_since"] === 0)
            $history[$username]["tty_only_since"] = $now;
        $history[$username]["samples"] = (int)$history[$username]["samples"] + 1;
    }
    else
    {
        $history[$username]["tty_only_since"] = 0;
        $history[$username]["samples"] = 0;
    }

    $duration = (int)$history[$username]["tty_only_since"] > 0 ? $now - (int)$history[$username]["tty_only_since"] : 0;
    $suspicious = $duration >= (int)$cfg["SuspiciousAfterSeconds"] && (int)$history[$username]["samples"] >= 3;

    return [
        "tty_only_seconds" => $duration,
        "tty_only_samples" => (int)$history[$username]["samples"],
        "suspicious" => $suspicious,
    ];
}

function persoc_activity_evaluate_user(string $username, string $mode, string $tty, int $idleSeconds, bool $lock): array
{
    $cfg = persoc_activity_configuration();
    if (!$cfg["Enabled"])
        return [
            "enabled" => false,
            "debug_enabled" => false,
            "active" => true,
            "state" => "active",
            "score" => 100,
            "reasons" => ["activity_disabled"],
        ];

    $now = persoc_activity_now();
    $score = 0;
    $reasons = [];

    $ttyRecent = $idleSeconds <= (int)$cfg["TTYRecentSeconds"];
    if ($ttyRecent)
    {
        $score += 20;
        $reasons[] = "tty_recent";
    }
    else if ($idleSeconds >= (int)$cfg["IdlePenaltySeconds"])
    {
        $score -= 35;
        $reasons[] = "tty_idle_long";
    }

    $fg = persoc_tty_foreground_process($tty);
    $foreground = (string)($fg["comm"] ?? "");
    $fgClass = persoc_activity_command_class($foreground, $cfg);

    if ($fgClass === "editor")
    {
        $score += 20;
        $reasons[] = "foreground_editor";
    }
    else if ($fgClass === "work")
    {
        $score += 35;
        $reasons[] = "foreground_work_command";
    }

    $files = persoc_activity_recent_files($username, $now, $cfg);
    if ((int)$files["count"] > 0)
    {
        $score += 35;
        $reasons[] = "recent_file_write";
    }
    if ((int)$files["source_count"] > 0)
    {
        $score += 15;
        $reasons[] = "recent_source_write";
    }
    if ((int)$files["count"] > 1)
    {
        $score += 10;
        $reasons[] = "multiple_recent_files";
    }

    if ($lock)
    {
        $score -= 60;
        $reasons[] = "locked";
    }

    $hasStrongSignal = ((int)$files["count"] > 0) || $fgClass === "work";
    $hist = persoc_activity_history($username, $ttyRecent, $hasStrongSignal, $now, $cfg);

    if (($hist["suspicious"] ?? false) === true)
    {
        $score -= 40;
        $reasons[] = "tty_active_without_work_signal";
    }

    // Hard cap: raw input alone, even with an editor in foreground, must not be
    // enough to claim real work. This is the anti stuck-key rule.
    if (!$hasStrongSignal && $ttyRecent)
        $score = min($score, $fgClass === "editor" ? 35 : 25);

    $score = max(0, min(100, $score));

    if (($hist["suspicious"] ?? false) === true)
        $state = "suspicious";
    else if ($score >= 70)
        $state = "active";
    else
        $state = "idle";

    return [
        "enabled" => true,
        "debug_enabled" => (bool)$cfg["Debug"],
        "active" => $state === "active",
        "state" => $state,
        "score" => $score,
        "reasons" => array_values(array_unique($reasons)),
        "tty" => $tty,
        "tty_idle_seconds" => $idleSeconds,
        "foreground" => $foreground,
        "foreground_pid" => isset($fg["pid"]) ? (int)$fg["pid"] : null,
        "file_write_count_recent" => (int)$files["count"],
        "source_write_count_recent" => (int)$files["source_count"],
        "last_file_write" => (int)$files["last_mtime"] > 0 ? date("c", (int)$files["last_mtime"]) : "",
        "file_write_samples" => $files["samples"],
        "tty_only_seconds" => (int)$hist["tty_only_seconds"],
    ];
}

function persoc_activity_mode(string $mode, array $activity): string
{
    // The public decision is intentionally small: Infosphere should not need to
    // interpret Persoc's heuristics.  A remote SSH session either counts as
    // active SSH work, or as an idle SSH connection.
    if ($mode !== "ssh")
        return $mode;
    if (($activity["enabled"] ?? false) !== true)
        return $mode;
    return (($activity["active"] ?? false) === true) ? "ssh" : "ssh_idle";
}

function persoc_activity_debug_fields(array $activity): array
{
    if (($activity["debug_enabled"] ?? false) !== true)
        return [];

    $out = [
        "activity_state" => (string)($activity["state"] ?? ""),
        "activity_score" => (int)($activity["score"] ?? 0),
        "activity_reasons" => $activity["reasons"] ?? [],
        "tty" => (string)($activity["tty"] ?? ""),
        "tty_idle_seconds" => (int)($activity["tty_idle_seconds"] ?? 0),
        "file_write_count_recent" => (int)($activity["file_write_count_recent"] ?? 0),
        "source_write_count_recent" => (int)($activity["source_write_count_recent"] ?? 0),
    ];

    if (($activity["foreground"] ?? "") !== "")
        $out["foreground"] = (string)$activity["foreground"];
    if (($activity["foreground_pid"] ?? null) !== null)
        $out["foreground_pid"] = (int)$activity["foreground_pid"];
    if (($activity["last_file_write"] ?? "") !== "")
        $out["last_file_write"] = (string)$activity["last_file_write"];
    if (is_array($activity["file_write_samples"] ?? null) && count($activity["file_write_samples"]) > 0)
        $out["file_write_samples"] = $activity["file_write_samples"];
    if ((int)($activity["tty_only_seconds"] ?? 0) > 0)
        $out["tty_only_seconds"] = (int)$activity["tty_only_seconds"];

    return $out;
}

/**
 * Returns array of:
 *  - username (string)
 *  - mode ("x" | "ssh" | "ssh_idle")
 *  - lock (bool)
 *  - last_activity (string "d/m/Y H:i:s")
 *  - optional activity_* debug fields only when Activity.Debug=true
 */
function persoc_users_get_activity(): array
{
    $users = [];

    // Classic `w` parsing (kept for compatibility / simplicity)
    $lst = @shell_exec("PROCPS_USERLEN=32 w 2>/dev/null | tr -s ' '");
    if (!is_string($lst) || trim($lst) === "")
        return $users;

    $lines = explode("\n", $lst);

    // Drop header lines (as your legacy code did)
    if (count($lines) >= 2) {
        array_shift($lines);
        array_shift($lines);
    }

    // Remove trailing empty
    while (count($lines) && trim(end($lines)) === "")
        array_pop($lines);

    $now = persoc_activity_now();

    foreach ($lines as $l)
    {
        $l = trim($l);
        if ($l === "") continue;

        $cols = explode(" ", $l);
        // Minimal sanity: expect at least idle column
        if (count($cols) < 5) continue;

        $username = $cols[0];
        $tty = $cols[1];
        $fromOrTty = $cols[2];
        $idle = $cols[4];
        $idleSeconds = persoc_parse_duration($idle);

        if (filter_var($fromOrTty, FILTER_VALIDATE_IP))
        {
            // SSH user
            $last = $now - $idleSeconds;
            $activity = persoc_activity_evaluate_user($username, "ssh", $tty, $idleSeconds, false);
            $row = [
                "username" => $username,
                "mode" => persoc_activity_mode("ssh", $activity),
                "lock" => false,
                "last_activity" => date("c", $last),
            ];
            $row += persoc_activity_debug_fields($activity);
            $users[] = $row;
        }
        else
        {
            // X (or local tty) user, we treat it as "x" for IH contract
            $lockAge = persoc_is_user_lock($username);
            if ($lockAge > 0) {
                $last = $now - $lockAge;
                $activity = persoc_activity_evaluate_user($username, "x", $tty, $lockAge, true);
                $row = [
                    "username" => $username,
                    "mode" => persoc_activity_mode("x", $activity),
                    "lock" => true,
                    "last_activity" => date("c", $last),
                ];
                $row += persoc_activity_debug_fields($activity);
                $users[] = $row;
            } else {
                $activity = persoc_activity_evaluate_user($username, "x", $tty, $idleSeconds, false);
                $row = [
                    "username" => $username,
                    "mode" => persoc_activity_mode("x", $activity),
                    "lock" => false,
                    "last_activity" => date("c", $now),
                ];
                $row += persoc_activity_debug_fields($activity);
                $users[] = $row;
            }
        }
    }

    return $users;
}

/**
 * Determine (iface, ip, mac) from `ip` command, without /sys.
 * @return array{iface:string, ip:string, mac:string}|null
 */
function persoc_get_net_identity(): ?array
{
    // Pick route to internet to determine iface + src IP
    $route = @shell_exec("ip route get 1.1.1.1 2>/dev/null");
    if (!is_string($route) || trim($route) === "")
        return null;

    // Parse: "... dev eth0 src 192.168.1.50 ..."
    $iface = null;
    $ip = null;

    if (preg_match('/\bdev\s+([a-zA-Z0-9_.:-]+)\b/', $route, $m))
        $iface = $m[1];
    if (preg_match('/\bsrc\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\b/', $route, $m))
        $ip = $m[1];

    if (!$iface || !$ip)
        return null;

    // MAC from ip link
    $link = @shell_exec("ip link show dev " . escapeshellarg($iface) . " 2>/dev/null");
    if (!is_string($link) || trim($link) === "")
        return null;

    $mac = null;
    if (preg_match('/\blink\/ether\s+([0-9a-fA-F:]{17})\b/', $link, $m))
        $mac = strtolower($m[1]);

    if (!$mac)
        return null;

    return ["iface" => $iface, "ip" => $ip, "mac" => $mac];
}

/**
 * Best-effort machine "type" (string required by IH log_activity).
 * You can refine later; for now: ID from /etc/os-release else "linux".
 */
function persoc_get_machine_type(): string
{
    $osr = @file_get_contents("/etc/os-release");
    if (is_string($osr)) {
        if (preg_match('/^ID=([a-zA-Z0-9._-]+)\s*$/m', $osr, $m))
            return $m[1];
    }
    return "linux";
}

/**
 * Main function: sends the packet to IH log_activity.
 * No params: host is $Configuration["Distrans"].
 *
 * @return array|null decoded JSON from IH (send_data semantics)
 */
function users_log_activity(): ?array
{
    global $Configuration;

    if (!isset($Configuration["Distrans"]) || !is_string($Configuration["Distrans"]) || trim($Configuration["Distrans"]) === "")
        return null;

    $id = persoc_get_net_identity();
    if ($id === null)
        return null;

    $name = @shell_exec("hostname 2>/dev/null");
    $name = is_string($name) ? trim($name) : "";
    if ($name === "")
        $name = "unknown";

    $packet = [
        "command" => "log_activity",
        "mac" => $id["mac"],
        "name" => $name,
        "ip" => $id["ip"],
        "type" => persoc_get_machine_type(),
        "users" => persoc_users_get_activity(),
    ];

    persoc_log("sending user log to distrans.", true);
    return send_data($Configuration["Distrans"], $packet);
}
