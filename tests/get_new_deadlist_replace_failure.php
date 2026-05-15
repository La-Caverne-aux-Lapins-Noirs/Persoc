#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("get_new_deadlist"), "get_new_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_get_new_deadlist_replace_failure");
$bin = $tmp . "/bin";
$sshArgs = $tmp . "/ssh.args.log";
$sshStdin = $tmp . "/ssh.stdin.dump";
$deadlistPath = $tmp . "/deadlist.csv";
@mkdir($deadlistPath, 0755, true);

install_mock_ssh($bin, $sshArgs, $sshStdin, json_encode([
    "result" => "ok",
    "deadlist_csv" => "1.2.3.4,ads\n",
], JSON_UNESCAPED_UNICODE));
install_mock_nft($bin, $tmp . "/nft.calls.log");

global $Configuration;
$Configuration = [
    "Distrans" => "ih.test",
    "Deadlist" => $deadlistPath,
];

$res = with_path_prefix($bin, function() {
    return get_new_deadlist();
});

assert_eq($res["ok"] ?? null, false, "replace over existing directory should fail");
assert_contains($res["error"] ?? "", "cannot replace deadlist file", "replace failure error message");
assert_true(is_dir($deadlistPath), "deadlist directory should still exist");
assert_true(!glob($deadlistPath . ".tmp.*"), "temporary deadlist file should be removed after rename failure");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
