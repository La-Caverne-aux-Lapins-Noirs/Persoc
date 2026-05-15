#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("users_log_activity"), "users_log_activity() not defined");
assert_true(function_exists("persoc_get_net_identity"), "persoc_get_net_identity() not defined");
assert_true(function_exists("persoc_users_get_activity"), "persoc_users_get_activity() not defined");

$tmp = mk_tmp_dir("persoc_unit_users_log_activity_errors");
$bin = $tmp . "/bin";

global $Configuration;
$Configuration = [];
assert_eq(users_log_activity(), null, "missing Distrans should return null");

$Configuration = ["Distrans" => "ih.test"];
install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
exit 1
SH);
$res = with_path_prefix($bin, function() {
    return users_log_activity();
});
assert_eq($res, null, "missing network identity should return null");

install_mock_cmd($bin, "w", <<<'SH'
#!/bin/sh
exit 0
SH);
$users = with_path_prefix($bin, function() {
    return persoc_users_get_activity();
});
assert_eq($users, [], "empty w output should produce no users");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
