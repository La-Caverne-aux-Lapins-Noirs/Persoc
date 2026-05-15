#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_exam"), "firewall_exam() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_exam_who_fallback");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
install_mock_nft($bin, $log);
put_exe($bin . "/id", <<<'SH'
#!/bin/sh
if [ "$1" != "-u" ]; then
  exit 1
fi
case "$2" in
  persocwhoalice) echo 41001; exit 0 ;;
  persocwhobob) echo 41002; exit 0 ;;
esac
exit 1
SH);

put_exe($bin . "/who", <<<'SH'
#!/bin/sh
echo "persocwhoalice tty2 2026-05-15 10:00 (:0)"
echo "persocwhobob pts/0 2026-05-15 10:00 (192.168.1.10)"
SH);

global $Configuration;
$Configuration = [
    "Distrans" => "10.10.0.10",
    "Custom" => [],
];

$oldPath = getenv("PATH") ?: "";
putenv("PATH=" . $bin);
try {
    $res = firewall_exam(true);
} finally {
    putenv("PATH=" . $oldPath);
}

assert_eq($res["ok"], true, "firewall_exam who fallback ok");
assert_eq($res["mode"], "exam", "firewall_exam who fallback mode");
assert_eq($res["graphical_users"], 1, "who fallback should keep only :0 session");
assert_eq($res["exam_uids"], 1, "who fallback should resolve one uid");
assert_eq($res["allowed_v4"], 1, "distrans ipv4 allowed count");
assert_eq($res["allowed_v6"], 0, "no ipv6 allowed count");

$calls = read_nft_calls($log);
$joined = implode("\n", $calls);
assert_contains($joined, "41001", "who fallback uid missing from nft calls");
assert_true(strpos($joined, "41002") === false, "non-graphical who user should not be filtered");
assert_contains($joined, "10.10.0.10", "distrans IP missing from allow set");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
