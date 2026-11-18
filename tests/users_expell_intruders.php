#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

$ROOT = realpath(__DIR__ . "/..");
assert_true($ROOT !== false, "Cannot resolve project root");

// Need send_data (ssh mocked) + users_expell_intruders
require_once $ROOT . "/src/tools/send_data.php";
require_once $ROOT . "/src/users/expell_intruders.php";

assert_true(function_exists("users_expell_intruders"), "users_expell_intruders() not defined");

$tmp = mk_tmp_dir("persoc_unit_users_expell");
$bin = $tmp . "/bin";

$sshArgsLog  = $tmp . "/ssh.args.log";
$sshStdin    = $tmp . "/ssh.stdin.dump";
$loginctlLog = $tmp . "/loginctl.log";
$noticeLog   = $tmp . "/notice.log";

// Network identity for IH is_exam (mac needed)
install_mock_ip_basic($bin, "eth0", "192.168.1.50", "aa:bb:cc:dd:ee:ff");

// Graphical sessions: alice (normal), john.doe.exam (exam account)
install_mock_loginctl_with_terminate($bin, $loginctlLog, [
    "10" => ["Name" => "alice",        "Type" => "wayland", "Class" => "user"],
    "11" => ["Name" => "john.doe.exam","Type" => "x11",     "Class" => "user"],
]);

global $Configuration;
$Configuration = [
    "Distrans" => "ih.test",
];

// ---- Scenario 1: manual exam mode keeps the exam account ----
$now = 1700000000;
putenv("PERSOC_NOW=" . $now);

$resp1 = json_encode([
    "result" => "ok",
    "exam" => true
], JSON_UNESCAPED_UNICODE);
assert_true(is_string($resp1), "json_encode resp1 failed");

install_mock_ssh($bin, $sshArgsLog, $sshStdin, $resp1);

with_path_prefix($bin, function() {
    $res = users_expell_intruders();
    assert_true(is_array($res) && ($res["ok"] ?? false) === true, "users_expell_intruders failed (scenario 1)");
    assert_true(($res["exam"] ?? null) === true, "scenario 1 should be exam=true");
    // alice should be killed, exam account should remain
    $k = $res["killed"] ?? [];
    assert_true(is_array($k), "killed must be array");
    assert_true(in_array("alice", $k, true), "alice must be expelled in exam mode");
    assert_true(!in_array("john.doe.exam", $k, true), "exam user must not be expelled in exam mode");
});

// Check loginctl terminate-session called for sid 10 (alice)
$lcalls = file_exists($loginctlLog) ? file($loginctlLog, FILE_IGNORE_NEW_LINES) : [];
$joined = is_array($lcalls) ? implode("\n", $lcalls) : "";
assert_contains($joined, "terminate-session|10", "should terminate session 10 (alice) in scenario 1");
assert_true(strpos($joined, "terminate-session|11") === false, "must NOT terminate exam session 11 in scenario 1");

// ---- Scenario 2: exam = false => expel john.doe.exam ----
@unlink($loginctlLog);

$resp2 = json_encode([
    "result" => "ok",
    "exam" => false
], JSON_UNESCAPED_UNICODE);
assert_true(is_string($resp2), "json_encode resp2 failed");

install_mock_ssh($bin, $sshArgsLog, $sshStdin, $resp2);

with_path_prefix($bin, function() {
    $res = users_expell_intruders();
    assert_true(is_array($res) && ($res["ok"] ?? false) === true, "users_expell_intruders failed (scenario 2)");
    assert_true(($res["exam"] ?? null) === false, "scenario 2 should be exam=false");
    $k = $res["killed"] ?? [];
    assert_true(in_array("john.doe.exam", $k, true), "exam user must be expelled when not in exam mode");
    assert_true(!in_array("alice", $k, true), "normal user must not be expelled when not in exam mode");
});

// loginctl terminate-session must target sid 11 now
$lcalls2 = file_exists($loginctlLog) ? file($loginctlLog, FILE_IGNORE_NEW_LINES) : [];
$joined2 = is_array($lcalls2) ? implode("\n", $lcalls2) : "";
assert_contains($joined2, "terminate-session|11", "should terminate session 11 (john.doe.exam) in scenario 2");
assert_true(strpos($joined2, "terminate-session|10") === false, "must NOT terminate alice session 10 in scenario 2");

// A scheduled exam warns current and new logins once, then checks the roster.
$start = $now + 600;
putenv('PERSOC_NOW=' . ($now - 1));
install_mock_cmd($bin, 'runuser', "#!/bin/sh\nexit 0\n");
install_mock_cmd($bin, 'timeout', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> " . escapeshellarg($noticeLog) . "\nexit 0\n");
$exam_sessions = [
    '20' => ['Name' => 'alice.bunny', 'Type' => 'x11', 'Class' => 'user', 'Display' => ':0'],
    '21' => ['Name' => 'bob.cat', 'Type' => 'tty', 'Class' => 'user', 'TTY' => 'tty2'],
    '26' => ['Name' => 'alice.bunny', 'Type' => 'tty', 'Class' => 'user', 'TTY' => 'pts/1', 'Remote' => 'yes'],
    '27' => ['Name' => 'carol.dog', 'Type' => 'x11', 'Class' => 'user', 'Display' => ':3', 'Service' => 'sshd'],
];
install_mock_loginctl_with_terminate($bin, $loginctlLog, $exam_sessions);
$upcoming = ['result' => 'ok', 'mode' => 'upcoming', 'exam' => false, 'session_id' => 42, 'start_at' => $start];
install_mock_ssh($bin, $sshArgsLog, $sshStdin, json_encode($upcoming));
$poll = static fn(): array => with_path_prefix($bin, 'users_expell_intruders');
assert_eq($poll()['warned'], false, 'no warning before ten minutes');
putenv('PERSOC_NOW=' . $now);
$open_warning = $poll();
assert_eq($open_warning['warned'], true, 'warning starts at ten minutes');
assert_eq($open_warning['killed'], [], 'ordinary sessions stay open during the warning');
$notices = file_get_contents($noticeLog);
assert_contains($notices, 'DISPLAY=:0', 'existing local graphical session gets a popup');
assert_contains($notices, '/dev/tty2', 'only the local text console gets a terminal notice');
assert_true(!str_contains($notices, 'pts/1') && !str_contains($notices, 'DISPLAY=:3'), 'remote sessions receive no warning');
assert_eq($poll()['warned'], false, 'same sessions are not warned again');

$exam_sessions['22'] = ['Name' => 'carol.dog', 'Type' => 'x11', 'Class' => 'user', 'Display' => ':1'];
install_mock_loginctl_with_terminate($bin, $loginctlLog, $exam_sessions);
putenv('PERSOC_NOW=' . ($now + 7));
assert_eq($poll()['warned'], true, 'new graphical login is warned between minute boundaries');

$exam_sessions['23'] = ['Name' => 'alice.bunny.exam', 'Type' => 'tty', 'Class' => 'user', 'TTY' => 'tty3'];
$exam_sessions['24'] = ['Name' => 'carol.dog.exam', 'Type' => 'x11', 'Class' => 'user'];
$exam_sessions['25'] = ['Name' => 'alice.bunny.exam', 'Type' => 'tty', 'Class' => 'user', 'Remote' => 'yes'];
install_mock_loginctl_with_terminate($bin, $loginctlLog, $exam_sessions);
@unlink($loginctlLog);
putenv('PERSOC_NOW=' . $start);
$active = array_replace($upcoming, ['exam' => true, 'mode' => 'exam', 'allowed_users' => ['alice.bunny.exam']]);
install_mock_ssh($bin, $sshArgsLog, $sshStdin, json_encode($active));
$active_result = $poll();
assert_eq($active_result['warned'], false, 'no warning once the exam starts');
assert_eq($active_result['exam_users'], ['alice.bunny.exam', 'carol.dog.exam'], 'ordinary accounts are excluded from UID filtering');
$calls = file_get_contents($loginctlLog);
foreach (['20', '21', '22', '24', '25'] as $sid)
    assert_contains($calls, 'terminate-session|' . $sid, 'unauthorized session ' . $sid . ' is closed');
assert_true(!str_contains($calls, 'terminate-session|23'), 'registered local exam console stays open');
assert_true(!str_contains($calls, 'terminate-session|26'), 'SSH using the same login as a closed desktop stays connected');
assert_true(!str_contains($calls, 'terminate-session|27'), 'the SSH PAM service also protects remote graphical logins');

@unlink($loginctlLog);
install_mock_ssh($bin, $sshArgsLog, $sshStdin, '{"result":"ok","mode":"normal","exam":false}');
$poll();
assert_contains(file_get_contents($loginctlLog), 'terminate-session|23', 'end closes the remaining exam session');

rm_rf($tmp);
fwrite(STDOUT, "OK ".basename(__FILE__)."\n");
exit(0);
