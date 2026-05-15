#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_deadlist"), "firewall_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_deadlist_hostname_localhost");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
$csv = $tmp . "/deadlist.csv";
file_put_contents($csv, "localhost,local resolver\n");

install_mock_nft($bin, $log);

$res = with_path_prefix($bin, function() use ($csv) {
    return firewall_deadlist($csv);
});

assert_eq($res["ok"], true, "localhost deadlist should succeed");
assert_eq($res["entries"], 1, "localhost should count as one entry");
assert_true(($res["blocked_v4"] + $res["blocked_v6"]) >= 1, "localhost should resolve to at least one address");

$calls = read_nft_calls($log);
$joined = implode("\n", $calls);
assert_true(strpos($joined, "127.0.0.1") !== false || strpos($joined, "::1") !== false, "localhost resolved address should be inserted into nft calls");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
