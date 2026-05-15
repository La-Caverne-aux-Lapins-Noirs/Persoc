#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("users_expell_intruders"), "users_expell_intruders() not defined");

$tmp = mk_tmp_dir("persoc_unit_users_expell_errors");
$bin = $tmp . "/bin";
$sshArgsLog = $tmp . "/ssh.args.log";
$sshStdin = $tmp . "/ssh.stdin.dump";

global $Configuration;

$Configuration = [];
$res = users_expell_intruders();
assert_eq($res["ok"] ?? true, false, "missing Distrans should fail");
assert_eq($res["error"] ?? "", "Configuration.Distrans missing", "missing Distrans error");

$Configuration = ["Distrans" => "ih.test"];
install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
exit 1
SH);
$res = with_path_prefix($bin, function() {
    return users_expell_intruders();
});
assert_eq($res["ok"] ?? true, false, "missing network identity should fail");
assert_eq($res["error"] ?? "", "cannot determine mac/ip", "network identity error");

install_mock_ip_basic($bin, "eth0", "192.168.1.50", "aa:bb:cc:dd:ee:ff");
install_mock_ssh($bin, $sshArgsLog, $sshStdin, "not json\n");
$res = with_path_prefix($bin, function() {
    return users_expell_intruders();
});
assert_eq($res["ok"] ?? true, false, "invalid is_exam response should fail");
assert_eq($res["error"] ?? "", "is_exam: no response", "invalid is_exam error");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
