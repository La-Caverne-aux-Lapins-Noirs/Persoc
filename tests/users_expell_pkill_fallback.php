#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("users_expell_intruders"), "users_expell_intruders() not defined");

$tmp = mk_tmp_dir("persoc_unit_users_expell_pkill");
$bin = $tmp . "/bin";
$sshArgsLog = $tmp . "/ssh.args.log";
$sshStdin = $tmp . "/ssh.stdin.dump";
$pkillLog = $tmp . "/pkill.calls.log";

install_mock_ip_basic($bin, "eth0", "192.168.1.50", "aa:bb:cc:dd:ee:ff");
install_mock_ssh($bin, $sshArgsLog, $sshStdin, json_encode(["result" => "ok", "exam" => true], JSON_UNESCAPED_UNICODE));
install_mock_cmd($bin, "who", <<<'SH'
#!/bin/sh
echo "alice tty7 2026-05-15 09:00 (:0)"
echo "john.doe.exam tty8 2026-05-15 09:00 (:1)"
SH);
$pkillLogEsc = str_replace("'", "'\"'\"'", $pkillLog);
install_mock_cmd($bin, "pkill", <<<SH
#!/bin/sh
echo "pkill|\$@" >> '$pkillLogEsc'
exit 0
SH);

global $Configuration;
$Configuration = ["Distrans" => "ih.test"];

$oldPath = getenv("PATH") ?: "";
putenv("PATH=" . $bin);
try {
    $res = users_expell_intruders();
} finally {
    putenv("PATH=" . $oldPath);
}

assert_true(is_array($res) && ($res["ok"] ?? false) === true, "users_expell_intruders should succeed");
assert_eq($res["graphical_sessions"] ?? -1, 2, "who fallback graphical session count");
$killed = $res["killed"] ?? [];
assert_true(is_array($killed), "killed must be array");
assert_true(in_array("alice", $killed, true), "normal user should be killed in exam mode via pkill fallback");
assert_true(!in_array("john.doe.exam", $killed, true), "exam account must not be killed in exam mode");

$pkill = file_exists($pkillLog) ? file_get_contents($pkillLog) : "";
assert_true(is_string($pkill), "pkill log unreadable");
assert_contains($pkill, "-KILL -u alice", "pkill fallback should target alice");
assert_true(strpos($pkill, "john.doe.exam") === false, "pkill fallback must not target exam account");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
