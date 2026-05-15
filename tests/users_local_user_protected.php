#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("users_expell_intruders"), "users_expell_intruders() not defined");

$tmp = mk_tmp_dir("persoc_unit_users_local_user_protected");
$bin = $tmp . "/bin";
$sshArgsLog = $tmp . "/ssh.args.log";
$sshStdin = $tmp . "/ssh.stdin.dump";
$loginctlLog = $tmp . "/loginctl.log";

install_mock_ip_basic($bin, "eth0", "192.168.1.50", "aa:bb:cc:dd:ee:ff");
install_mock_loginctl_with_terminate($bin, $loginctlLog, [
    "10" => ["Name" => "technocore", "Type" => "wayland", "Class" => "user"],
    "11" => ["Name" => "alice",      "Type" => "x11",     "Class" => "user"],
]);

$resp = json_encode(["result" => "ok", "exam" => true], JSON_UNESCAPED_UNICODE);
assert_true(is_string($resp), "json_encode response failed");
install_mock_ssh($bin, $sshArgsLog, $sshStdin, $resp);

global $Configuration;
$Configuration = [
    "Distrans" => "ih.test",
    "LocalUser" => "technocore",
];

$res = with_path_prefix($bin, function() {
    return users_expell_intruders();
});

assert_true(is_array($res) && ($res["ok"] ?? false) === true, "users_expell_intruders failed");
assert_eq($res["exam"] ?? null, true, "exam mode expected");
assert_eq($res["graphical_sessions"] ?? -1, 2, "graphical session count");

$killed = $res["killed"] ?? [];
assert_true(is_array($killed), "killed must be array");
assert_true(!in_array("technocore", $killed, true), "LocalUser must not be reported as killed");
assert_true(in_array("alice", $killed, true), "regular user should be killed in exam mode");

$calls = file_exists($loginctlLog) ? file($loginctlLog, FILE_IGNORE_NEW_LINES) : [];
$joined = is_array($calls) ? implode("\n", $calls) : "";
assert_true(strpos($joined, "terminate-session|10") === false, "LocalUser session must not be terminated");
assert_contains($joined, "terminate-session|11", "regular user session should be terminated");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
