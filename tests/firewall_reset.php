#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_reset"), "firewall_reset() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_reset");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";

install_mock_nft($bin, $log);

$res = with_path_prefix($bin, function() {
    return firewall_reset();
});

assert_eq($res["ok"], true, "firewall_reset ok");
assert_eq($res["error"], "", "firewall_reset error");
assert_eq($res["commands"], 2, "firewall_reset command count");

$calls = read_nft_calls($log);
assert_eq(count($calls), 2, "firewall_reset nft call count");
assert_eq($calls[0], "nft|flush|ruleset", "firewall_reset flush ruleset");
assert_eq($calls[1], "nft|add|table|inet|filter", "firewall_reset add table");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
