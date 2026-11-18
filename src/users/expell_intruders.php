<?php
declare(strict_types=1);

// Distrans decides the room mode and which exam accounts may use this workstation.

function persoc_now(): int
{
    // For tests: allow deterministic time without adding function params.
    $v = getenv("PERSOC_NOW");
    if (is_string($v) && preg_match('/^\d+$/', $v) === 1)
        return (int)$v;
    return time();
}

/** a.b.exam */
function persoc_is_exam_account(string $username): bool
{
    return preg_match('/^[^.]+\.[^.]+\.exam$/', $username) === 1;
}

function persoc_is_maintenance_user(string $user): bool
{
    global $Configuration;

    return $user === 'root' || $user === ($Configuration['LocalUser'] ?? null);
}

function persoc_exam_graphical_session(array $session): bool
{
    return in_array($session['type'], ['x11', 'wayland'], true);
}

function persoc_exam_managed_login(string $user): bool
{
    return persoc_is_exam_account($user) || preg_match('/^[a-z]+\.[a-z]+[0-9]*$/D', $user) === 1;
}

function persoc_list_exam_sessions(): array
{
    $sessions = [];
    $list = @shell_exec('loginctl list-sessions --no-legend 2>/dev/null');
    if (is_string($list) && trim($list) !== '')
    {
        foreach (explode("\n", trim($list)) as $line)
        {
            $sid = preg_split('/\s+/', trim($line), 2)[0];
            if (!preg_match('/^[a-zA-Z0-9]+$/D', $sid))
                continue;

            $info = @shell_exec('loginctl show-session ' . escapeshellarg($sid)
                . ' -p Name -p Type -p Class -p Display -p TTY -p Remote -p Service 2>/dev/null');
            if (!is_string($info) || trim($info) === '')
                continue;

            $properties = [];
            foreach (explode("\n", trim($info)) as $property)
                if (str_contains($property, '='))
                {
                    [$key, $value] = explode('=', $property, 2);
                    $properties[$key] = $value;
                }

            $user = $properties['Name'] ?? '';
            if ($user === '' || ($properties['Class'] ?? 'user') !== 'user' || persoc_is_maintenance_user($user))
                continue;

            $tty = $properties['TTY'] ?? '';
            if (str_starts_with($tty, '/dev/'))
                $tty = substr($tty, 5);
            $service = $properties['Service'] ?? '';
            $remote = ($properties['Remote'] ?? '') === 'yes' || str_starts_with($tty, 'pts/')
                || str_starts_with($service, 'sshd') || str_starts_with($service, 'x2go');
            $session = [
                'sid' => $sid,
                'user' => $user,
                'type' => $properties['Type'] ?? '',
                'display' => $properties['Display'] ?? '',
                'tty' => $tty,
                'remote' => $remote,
            ];
            if (persoc_exam_graphical_session($session) ||
                (($session['type'] === 'tty' || $remote) && persoc_exam_managed_login($user)))
                $sessions[] = $session;
        }
        return $sessions;
    }

    $who = (string)@shell_exec('who 2>/dev/null');
    foreach (explode("\n", trim($who)) as $line)
    {
        if (!preg_match('~^(\S+)\s+(\S+)\s+~', $line, $entry) || persoc_is_maintenance_user($entry[1]))
            continue;
        $display = preg_match('/\(\s*(:[0-9]+(?:\.[0-9]+)?)\s*\)$/D', trim($line), $match) ? $match[1] : '';
        if ($display !== '' || persoc_exam_managed_login($entry[1]))
            $sessions[] = ['sid' => '', 'user' => $entry[1], 'tty' => $entry[2],
                'type' => $display === '' ? 'tty' : 'x11', 'display' => $display,
                'remote' => str_starts_with($entry[2], 'pts/')];
    }
    return $sessions;
}

function persoc_terminate_exam_session(array $session): bool
{
    // A user-wide termination would also close that user's SSH connections.
    if ($session['sid'] === '')
        return false;
    @exec('loginctl terminate-session ' . escapeshellarg($session['sid']) . ' 2>/dev/null', $out, $status);
    return $status === 0;
}

/** Parse start_at from IH response; accept int unix or strtotime-compatible string. Returns unix timestamp or null. */
function persoc_parse_start_at($start_at): ?int
{
    if (is_int($start_at))
        return $start_at;

    if (is_string($start_at))
    {
        $s = trim($start_at);
        if ($s === "") return null;

        if (preg_match('/^\d+$/', $s) === 1)
            return (int)$s;

        $t = strtotime($s);
        if ($t !== false)
            return (int)$t;
    }

    return null;
}

/** get mac/ip without parameters; minimal for is_exam query (mac required). */
function persoc_get_net_identity_for_exam(): ?array
{
    // identical idea to your earlier helper: route get => iface+src, then link show => mac
    $route = @shell_exec("ip route get 1.1.1.1 2>/dev/null");
    if (!is_string($route) || trim($route) === "")
        return null;

    $iface = null;
    $ip = null;

    if (preg_match('/\bdev\s+([a-zA-Z0-9_.:-]+)\b/', $route, $m))
        $iface = $m[1];
    if (preg_match('/\bsrc\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\b/', $route, $m))
        $ip = $m[1];

    if (!$iface || !$ip)
        return null;

    $link = @shell_exec("ip link show dev " . escapeshellarg($iface) . " 2>/dev/null");
    if (!is_string($link) || trim($link) === "")
        return null;

    $mac = null;
    if (preg_match('/\blink\/ether\s+([0-9a-fA-F:]{17})\b/', $link, $m))
        $mac = strtolower($m[1]);

    if (!$mac)
        return null;

    return ["ip" => $ip, "mac" => $mac];
}

function persoc_warn_exam_sessions(array $sessions, string $exam, int $seconds): bool
{
    static $last_exam = null;
    static $sent = [];

    if ($last_exam !== $exam)
    {
        $last_exam = $exam;
        $sent = [];
    }

    $minutes = (int)ceil($seconds / 60);
    $message = "Examen imminent. Début dans $minutes min.\nEnregistre ton travail et libère le poste avant le début.";
    $present = [];
    $warned = false;
    foreach ($sessions as $session)
    {
        if ($session['remote'] || persoc_is_exam_account($session['user']))
            continue;
        $key = $session['user'] . ':' . ($session['sid'] !== '' ? $session['sid'] : $session['tty']);
        $present[$key] = true;
        if (isset($sent[$key]))
            continue;
        $delivered = persoc_exam_graphical_session($session)
            ? persoc_exam_popup($session, $message)
            : persoc_exam_console_notice($session, $message);
        if ($delivered)
        {
            $sent[$key] = true;
            $warned = true;
        }
    }
    $sent = array_intersect_key($sent, $present);
    return $warned;
}

function persoc_exam_console_notice(array $session, string $message): bool
{
    if (!preg_match('/^tty[1-9][0-9]*$/D', $session['tty']))
        return false;

    // Write to this console only. A broadcast would also reach remote terminals.
    $print = 'test -c "$1" && printf "\\r\\n%s\\r\\n" "$2" > "$1"';
    $args = ['timeout', '1', 'sh', '-c', $print, 'sh', '/dev/' . $session['tty'], $message];
    @exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null', $out, $status);
    return $status === 0;
}

function persoc_exam_popup(array $session, string $message, string $title = 'Examen imminent'): bool
{
    if (!preg_match('/^:[0-9]+(?:\.[0-9]+)?$/D', $session['display']))
        return false;

    // Run the X client as the account that owns the LightDM session and its Xauthority.
    $args = ['runuser', '-u', $session['user'], '--', 'env', '-u', 'XAUTHORITY', 'DISPLAY=' . $session['display']];
    $command = implode(' ', array_map('escapeshellarg', $args));
    @exec('timeout 1 ' . $command . ' xdpyinfo >/dev/null 2>&1', $out, $status);
    if ($status !== 0)
        return false;

    // The dialog can stay open without blocking the next check of the room state.
    @exec($command . ' xmessage -title ' . escapeshellarg($title) . ' -center -timeout 30 -buttons OK:0 -default OK '
        . escapeshellarg($message) . ' </dev/null >/dev/null 2>&1 &', $out, $status);
    return $status === 0;
}

function persoc_exam_feedback(array $sessions, array $timing, int $now): array
{
    static $sent = [];
    $notices = [];
    $deadlines = [];
    foreach ($sessions as $session)
    {
        $user = $session['user'];
        $end = $timing[$user]['end_at'] ?? null;
        if (!is_int($end) || $end <= $now)
            continue;
        $graphical = persoc_exam_graphical_session($session);
        if ($graphical)
            $deadlines[$user] = $end;
        $next = $timing[$user]['next_intermediate_at'] ?? null;
        if (!is_int($next) || $next <= $now || $next >= $end || $next - $now > 300)
            continue;

        $terminal = $session['sid'] !== '' ? $session['sid'] : $session['tty'];
        $key = implode(':', [$user, $terminal, $timing[$user]['session_id'] ?? '', $next]);
        if (!isset($sent[$key]))
        {
            $minutes = (int)ceil(($next - $now) / 60);
            $message = "Moulinette intermédiaire dans $minutes min.\n"
                . "Enregistre tes fichiers. Le résultat sera disponible dans ~/traces.txt.";
            $delivered = $graphical
                ? persoc_exam_popup($session, $message, 'Moulinette intermédiaire')
                : persoc_exam_console_notice($session, $message);
            if (!$delivered)
                continue;
        }
        $notices[$key] = true;
    }
    $sent = $notices;
    return $deadlines;
}

function persoc_publish_exam_deadlines(array $deadlines, string $path = '/run/persoc-exam-deadlines.json'): bool
{
    ksort($deadlines);
    $json = json_encode((object)$deadlines);
    if ($json === false)
        return false;
    if (is_file($path) && @file_get_contents($path) === $json)
        return true;
    $tmp = @tempnam(dirname($path), '.persoc-exam-');
    if ($tmp === false)
        return false;
    try
    {
        return @file_put_contents($tmp, $json) === strlen($json) && @chmod($tmp, 0644) && @rename($tmp, $path);
    }
    finally
    {
        if (is_file($tmp))
            @unlink($tmp);
    }
}

function users_expell_intruders(): array
{
    global $Configuration;

    if (!isset($Configuration["Distrans"]) || !is_string($Configuration["Distrans"]) || trim($Configuration["Distrans"]) === "")
        return ["ok" => false, "error" => "Configuration.Distrans missing"];

    if (!function_exists("send_data"))
        return ["ok" => false, "error" => "send_data() missing"];

    $id = persoc_get_net_identity_for_exam();
    if ($id === null)
        return ["ok" => false, "error" => "cannot determine mac/ip"];

    // Ask IH
    $ans = send_data($Configuration["Distrans"], [
        "command" => "is_exam",
        "mac" => $id["mac"],
        "ip" => $id["ip"],
    ]);

    if (!is_array($ans))
        return ["ok" => false, "error" => "is_exam: no response"];

    if (($ans['result'] ?? 'ok') !== 'ok' || ($ans['status'] ?? 'ok') !== 'ok' || !is_bool($ans['exam'] ?? null))
        return ['ok' => false, 'error' => 'is_exam: invalid room status'];
    if (($ans['machine_verified'] ?? true) === false || (array_key_exists('room', $ans) && $ans['room'] === null))
        return ['ok' => false, 'error' => 'is_exam: workstation is not assigned to a room'];

    $exam = $ans['exam'];
    $start_at = persoc_parse_start_at($ans["start_at"] ?? null);
    $allowed_users = $ans['allowed_users'] ?? null;
    if ($exam && (isset($ans['session_id']) || $allowed_users !== null))
    {
        if (!is_array($allowed_users) || !array_is_list($allowed_users))
            return ['ok' => false, 'error' => 'is_exam: missing exam roster'];
        foreach ($allowed_users as $account)
            if (!is_string($account) || !persoc_is_exam_account($account))
                return ['ok' => false, 'error' => 'is_exam: invalid exam roster'];
    }

    $sessions = persoc_list_exam_sessions();
    $now = persoc_now();
    $seconds = $start_at === null ? 0 : $start_at - $now;
    $warned = $seconds > 0 && $seconds <= 600 && persoc_warn_exam_sessions($sessions,
        ($ans['room'] ?? '') . ':' . ($ans['session_id'] ?? '') . ':' . $start_at, $seconds);

    $killed = [];
    $exam_users = [];
    $candidate_sessions = [];
    foreach ($sessions as $session)
    {
        $user = $session['user'];
        $exam_account = persoc_is_exam_account($user);
        if ($session['remote'] && !$exam_account)
            continue;

        $close = $session['remote'] || ($exam
            ? (!$exam_account || ($allowed_users !== null && !in_array($user, $allowed_users, true)))
            : $exam_account);
        if ($close)
        {
            if (persoc_terminate_exam_session($session))
                $killed[] = $user;
            else
                persoc_log('Could not terminate exam session for ' . $user);
        }
        if ($exam && $exam_account && !$session['remote'])
        {
            $exam_users[$user] = true;
            if (!$close)
                $candidate_sessions[] = $session;
        }
    }

    $timing = is_array($ans['candidate_timing'] ?? null) ? $ans['candidate_timing'] : [];
    if (!array_key_exists('candidate_timing', $ans) && $exam && isset($ans['end_at']))
        $timing = array_fill_keys(array_keys($exam_users), ['end_at' => persoc_parse_start_at($ans['end_at'])]);
    return [
        "ok" => true,
        "exam" => $exam,
        "start_at" => $start_at,
        "warned" => $warned,
        "killed" => $killed,
        "graphical_sessions" => count(array_filter($sessions, 'persoc_exam_graphical_session')),
        "exam_users" => array_keys($exam_users),
        "exam_deadlines" => persoc_exam_feedback($candidate_sessions, $timing, $now),
    ];
}
