#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_exam"), "firewall_exam() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_exam_no_graphical");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
install_mock_nft($bin, $log);
install_mock_loginctl($bin, [
    "2" => ["Name" => "persoctty", "Type" => "tty", "Class" => "user"],
    "3" => ["Name" => "persocgreeter", "Type" => "wayland", "Class" => "greeter"],
]);
install_mock_id($bin, [
    "persoctty" => 43001,
    "persocgreeter" => 43002,
]);

global $Configuration;
$Configuration = [
    "Distrans" => "10.10.0.10",
    "Custom" => [],
];

$res = with_path_prefix($bin, function() {
    return firewall_exam(true);
});

assert_eq($res["ok"], true, "firewall_exam no graphical ok");
assert_eq($res["mode"], "exam", "firewall_exam no graphical mode");
assert_eq($res["graphical_users"], 0, "no graphical users should be counted");
assert_eq($res["exam_uids"], 0, "no exam uids should be inserted");
assert_eq($res["allowed_v4"], 1, "allowed v4 still configured");

$calls = read_nft_calls($log);
$joined = implode("\n", $calls);
assert_contains($joined, "exam_uids", "exam uid set should still be managed");
assert_contains($joined, "10.10.0.10", "allowed destination should still be inserted");
assert_true(strpos($joined, "43001") === false, "tty user uid should not be inserted");
assert_true(strpos($joined, "43002") === false, "greeter uid should not be inserted");
assert_true(!nft_calls_has($calls, '/^nft\|add\|element\|inet\|filter\|exam_uids\b/'), "exam_uids add element must be skipped when empty");
assert_true(nft_calls_has($calls, '/^nft\|add\|rule\|inet\|filter\|exam_out\|meta\|skuid\|@exam_uids\|drop\b/'), "drop rule should still exist");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
