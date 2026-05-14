#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("get_new_deadlist"), "get_new_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_get_new_deadlist_array");
$bin = $tmp . "/bin";
$sshArgsLog = $tmp . "/ssh.args.log";
$sshStdin = $tmp . "/ssh.stdin.dump";
$nftLog = $tmp . "/nft.calls.log";

install_mock_ssh($bin, $sshArgsLog, $sshStdin, json_encode([
    "result" => "ok",
    "deadlist" => [
        "5.6.7.8",
        "2001:db8::42",
        "",
        "   ",
    ],
], JSON_UNESCAPED_UNICODE));
install_mock_nft($bin, $nftLog);

global $Configuration;
$Configuration = [
    "Distrans" => "ih.test",
    "Deadlist" => $tmp . "/deadlist.csv",
];

$res = with_path_prefix($bin, function() {
    return get_new_deadlist();
});

assert_true(is_array($res) && ($res["ok"] ?? false) === true, "get_new_deadlist array response should succeed");
assert_eq($res["path"] ?? "", $Configuration["Deadlist"], "deadlist path");

$csv = file_get_contents($Configuration["Deadlist"]);
assert_true(is_string($csv), "deadlist file not readable");
assert_contains($csv, "5.6.7.8,remote", "deadlist array missing ipv4 remote line");
assert_contains($csv, "2001:db8::42,remote", "deadlist array missing ipv6 remote line");

$calls = read_nft_calls($nftLog);
$joined = implode("\n", $calls);
assert_contains($joined, "5.6.7.8", "nft calls missing ipv4 element insertion");
assert_contains($joined, "2001:db8::42", "nft calls missing ipv6 element insertion");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
