#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_exam"), "firewall_exam() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_exam_cidr");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
install_mock_nft($bin, $log);
install_mock_loginctl($bin, [
    "7" => ["Name" => "persoccidruser", "Type" => "x11", "Class" => "user"],
]);
install_mock_id($bin, [
    "persoccidruser" => 42001,
]);

global $Configuration;
$Configuration = [
    "Distrans" => ["10.10.0.10", "2001:db8::10"],
    "Custom" => ["10.10.0.0/24", "2001:db8::/32", "", "10.10.0.10"],
];

$res = with_path_prefix($bin, function() {
    return firewall_exam(true);
});

assert_eq($res["ok"], true, "firewall_exam cidr ok");
assert_eq($res["mode"], "exam", "firewall_exam cidr mode");
assert_eq($res["graphical_users"], 1, "cidr graphical users");
assert_eq($res["exam_uids"], 1, "cidr exam uids");
assert_eq($res["allowed_v4"], 2, "cidr v4 count should include IP and CIDR once");
assert_eq($res["allowed_v6"], 2, "cidr v6 count should include IP and CIDR once");

$calls = read_nft_calls($log);
$joined = implode("\n", $calls);
foreach (["42001", "10.10.0.10", "10.10.0.0/24", "2001:db8::10", "2001:db8::/32"] as $needle)
    assert_contains($joined, $needle, "missing nft token: " . $needle);

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
