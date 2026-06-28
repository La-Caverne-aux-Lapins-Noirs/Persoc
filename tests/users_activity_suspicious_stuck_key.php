#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_users_get_activity"), "persoc_users_get_activity() not defined");

$tmp = mk_tmp_dir("persoc_unit_activity_suspicious");
$bin = $tmp . "/bin";

global $Configuration;
$Configuration = [
    "Activity" => [
        "HomePattern" => $tmp . "/home/%u",
        "WorkPaths" => ["work"],
        "RecentFileSeconds" => 900,
        "FilesystemScanEvery" => 1,
        "SuspiciousAfterSeconds" => 10,
    ],
];

install_mock_cmd($bin, "w", <<<'SH'
#!/bin/sh
echo " 17:00:00 up 1 day,  1 user,  load average: 0.00, 0.00, 0.00"
echo "USER     TTY      FROM             LOGIN@   IDLE   JCPU   PCPU WHAT"
echo "mallory  pts/2    192.168.1.77     16:30   .      0.01s  0.01s emacs"
SH);

install_mock_cmd($bin, "ps", <<<'SH'
#!/bin/sh
if [ "$1" = "-t" ]; then
  echo "200 1 200 200 S emacs emacs suspicious.c"
  exit 0
fi
exit 0
SH);

$last = [];
foreach ([1000, 1006, 1012] as $now)
{
    putenv("PERSOC_NOW=" . $now);
    $rows = with_path_prefix($bin, function() {
        return persoc_users_get_activity();
    });
    assert_eq(count($rows), 1, "one user expected at now=$now");
    $last = $rows[0];
}

assert_eq($last["mode"] ?? "", "ssh_idle", "repeated tty-only input should be exposed as ssh_idle");
assert_true(!array_key_exists("activity_state", $last), "debug activity_state should be hidden by default");
assert_true(!array_key_exists("activity_score", $last), "debug activity_score should be hidden by default");

putenv("PERSOC_NOW");
rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
