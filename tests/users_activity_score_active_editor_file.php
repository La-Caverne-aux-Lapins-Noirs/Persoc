#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_users_get_activity"), "persoc_users_get_activity() not defined");

$tmp = mk_tmp_dir("persoc_unit_activity_score_active");
$bin = $tmp . "/bin";
$home = $tmp . "/home/bob";
$work = $home . "/work";
@mkdir($work, 0755, true);

$now = 1700000000;
putenv("PERSOC_NOW=" . $now);

$file = $work . "/main.c";
file_put_contents($file, "int main(void) { return 0; }\n");
touch($file, $now - 60);

global $Configuration;
$Configuration = [
    "Activity" => [
        "HomePattern" => $tmp . "/home/%u",
        "WorkPaths" => ["work"],
        "RecentFileSeconds" => 900,
        "FilesystemScanEvery" => 1,
    ],
];

install_mock_cmd($bin, "w", <<<'SH'
#!/bin/sh
echo " 17:00:00 up 1 day,  1 user,  load average: 0.00, 0.00, 0.00"
echo "USER     TTY      FROM             LOGIN@   IDLE   JCPU   PCPU WHAT"
echo "bob      pts/0    192.168.1.70     16:30   0:10   0.01s  0.01s emacs"
SH);

install_mock_cmd($bin, "ps", <<<'SH'
#!/bin/sh
if [ "$1" = "-t" ]; then
  echo "100 1 100 100 S emacs emacs main.c"
  exit 0
fi
exit 0
SH);

$res = with_path_prefix($bin, function() {
    return persoc_users_get_activity();
});

assert_eq(count($res), 1, "one user expected");
$u = $res[0];
assert_eq($u["username"] ?? "", "bob", "username");
assert_eq($u["mode"] ?? "", "ssh", "mode");
assert_true(!array_key_exists("activity_state", $u), "debug activity_state should be hidden by default");
assert_true(!array_key_exists("activity_score", $u), "debug activity_score should be hidden by default");

putenv("PERSOC_NOW");
rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
