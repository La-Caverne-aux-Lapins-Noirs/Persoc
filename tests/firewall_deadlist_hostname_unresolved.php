#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_deadlist"), "firewall_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_deadlist_hostname_unresolved");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
$csv = $tmp . "/deadlist.csv";
file_put_contents($csv, "definitely-not-a-real-hostname.invalid,unresolved\n");

install_mock_nft($bin, $log);

$res = with_path_prefix($bin, function() use ($csv) {
    return firewall_deadlist($csv);
});

assert_eq($res["ok"], true, "unresolved hostname deadlist should still succeed");
assert_eq($res["entries"], 1, "unresolved hostname should count as entry");
assert_eq($res["blocked_v4"], 0, "unresolved hostname should not add v4");
assert_eq($res["blocked_v6"], 0, "unresolved hostname should not add v6");

$calls = read_nft_calls($log);
$joined = implode("\n", $calls);
assert_true(strpos($joined, "add|element|inet|filter|deadlist_v4") === false, "unresolved hostname must not add v4 elements");
assert_true(strpos($joined, "add|element|inet|filter|deadlist_v6") === false, "unresolved hostname must not add v6 elements");
assert_true(nft_calls_has($calls, '/^nft\|add\|rule\|inet\|filter\|output\|ip\|daddr\|@deadlist_v4\|drop/'), "v4 rule should still be ensured");
assert_true(nft_calls_has($calls, '/^nft\|add\|rule\|inet\|filter\|output\|ip6\|daddr\|@deadlist_v6\|drop/'), "v6 rule should still be ensured");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
