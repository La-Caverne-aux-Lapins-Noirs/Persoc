#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("get_new_deadlist"), "get_new_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_get_new_deadlist_errors");
$bin = $tmp . "/bin";
$argsLog = $tmp . "/ssh.args.log";
$stdinDump = $tmp . "/ssh.stdin.dump";
$deadlist = $tmp . "/deadlist.csv";

global $Configuration;

$Configuration = ["Deadlist" => $deadlist];
$res = get_new_deadlist();
assert_eq($res["ok"] ?? null, false, "missing Distrans should fail");
assert_contains($res["error"] ?? "", "Distrans", "missing Distrans error message");

$Configuration = ["Distrans" => "ih.test"];
$res = get_new_deadlist();
assert_eq($res["ok"] ?? null, false, "missing Deadlist should fail");
assert_contains($res["error"] ?? "", "Deadlist", "missing Deadlist error message");

$Configuration = ["Distrans" => "ih.test", "Deadlist" => $deadlist];

install_mock_ssh($bin, $argsLog, $stdinDump, "not json\n");
$res = with_path_prefix($bin, function() {
    return get_new_deadlist();
});
assert_eq($res["ok"] ?? null, false, "invalid response should fail");
assert_contains($res["error"] ?? "", "invalid response", "invalid response error message");

install_mock_ssh($bin, $argsLog, $stdinDump, json_encode(["result" => "ko", "message" => "refused"], JSON_UNESCAPED_UNICODE));
$res = with_path_prefix($bin, function() {
    return get_new_deadlist();
});
assert_eq($res["ok"] ?? null, false, "distrans error should fail");
assert_contains($res["error"] ?? "", "distrans returned error", "distrans error message");

install_mock_ssh($bin, $argsLog, $stdinDump, json_encode(["result" => "ok"], JSON_UNESCAPED_UNICODE));
$res = with_path_prefix($bin, function() {
    return get_new_deadlist();
});
assert_eq($res["ok"] ?? null, false, "missing content should fail");
assert_contains($res["error"] ?? "", "missing deadlist content", "missing content error message");

assert_true(!file_exists($deadlist), "failing deadlist refresh must not create final file");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
