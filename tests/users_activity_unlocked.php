#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_users_get_activity"), "persoc_users_get_activity() not defined");
assert_true(function_exists("persoc_is_user_lock"), "persoc_is_user_lock() not defined");

$tmp = mk_tmp_dir("persoc_unit_users_activity_unlocked");
$bin = $tmp . "/bin";

install_mock_cmd($bin, "w", <<<'SH'
#!/bin/sh
echo " 17:00:00 up 1 day,  1 user,  load average: 0.00, 0.00, 0.00"
echo "USER     TTY      FROM             LOGIN@   IDLE   JCPU   PCPU WHAT"
echo "alice    tty7     :0               16:00   .      0.01s  0.01s -"
SH);
install_mock_cmd($bin, "ps", <<<'SH'
#!/bin/sh
exit 0
SH);

$res = with_path_prefix($bin, function() {
    assert_eq(persoc_is_user_lock(""), 0, "empty user lock");
    assert_eq(persoc_is_user_lock("alice"), 0, "unlocked user lock age");
    return persoc_users_get_activity();
});

assert_eq(count($res), 1, "one activity row expected");
assert_eq($res[0]["username"] ?? "", "alice", "activity username");
assert_eq($res[0]["mode"] ?? "", "x", "activity x mode");
assert_eq($res[0]["lock"] ?? true, false, "unlocked x user");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
