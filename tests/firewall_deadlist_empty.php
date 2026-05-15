#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_deadlist"), "firewall_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_deadlist_empty");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
$csv = $tmp . "/deadlist.csv";
file_put_contents($csv, "\n# only comments\n  \n,empty target\n");

install_mock_nft($bin, $log);

$res = with_path_prefix($bin, function() use ($csv) {
    return firewall_deadlist($csv);
});

assert_eq($res["ok"], true, "empty/comment deadlist should succeed");
assert_eq($res["entries"], 0, "empty/comment deadlist entry count");
assert_eq($res["blocked_v4"], 0, "empty/comment deadlist v4 count");
assert_eq($res["blocked_v6"], 0, "empty/comment deadlist v6 count");

$calls = read_nft_calls($log);
$joined = implode("\n", $calls);
assert_true(strpos($joined, "add|element|inet|filter|deadlist_v4") === false, "empty deadlist must not add v4 elements");
assert_true(strpos($joined, "add|element|inet|filter|deadlist_v6") === false, "empty deadlist must not add v6 elements");
assert_true(nft_calls_has($calls, '/^nft\|flush\|set\|inet\|filter\|deadlist_v4/'), "empty deadlist should still flush v4 set");
assert_true(nft_calls_has($calls, '/^nft\|flush\|set\|inet\|filter\|deadlist_v6/'), "empty deadlist should still flush v6 set");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
