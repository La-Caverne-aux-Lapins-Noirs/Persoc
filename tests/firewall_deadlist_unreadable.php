#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_deadlist"), "firewall_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_deadlist_unreadable");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
install_mock_nft($bin, $log);

$missing = $tmp . "/missing.csv";
$res = with_path_prefix($bin, function() use ($missing) {
    return firewall_deadlist($missing);
});

assert_true(is_array($res), "firewall_deadlist unreadable should return array");
assert_eq($res["ok"] ?? null, false, "unreadable deadlist ok flag");
assert_contains($res["error"] ?? "", "deadlist not readable", "unreadable deadlist error");
assert_eq($res["blocked_v4"] ?? -1, 0, "unreadable blocked_v4");
assert_eq($res["blocked_v6"] ?? -1, 0, "unreadable blocked_v6");
assert_eq($res["entries"] ?? -1, 0, "unreadable entries");
assert_eq(read_nft_calls($log), [], "unreadable deadlist must not touch nft");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
