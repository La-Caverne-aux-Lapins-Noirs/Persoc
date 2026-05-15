#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_deadlist"), "firewall_deadlist() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_deadlist_errors");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
$csv = $tmp . "/deadlist.csv";
file_put_contents($csv, "1.2.3.4,ads\n");

@mkdir($bin, 0755, true);
$logEsc = str_replace("'", "'\"'\"'", $log);
install_mock_cmd($bin, "nft", <<<SH
#!/bin/sh
out="nft"
for a in "\$@"; do
  out="\$out|\$a"
done
echo "\$out" >> '$logEsc'

if [ "\$1" = "add" ] && [ "\$2" = "element" ] && [ "\$5" = "deadlist_v4" ]; then
  exit 9
fi
exit 0
SH);

$res = with_path_prefix($bin, function() use ($csv) {
    return firewall_deadlist($csv);
});

assert_eq($res["ok"], false, "deadlist should report nft error");
assert_contains($res["error"], "nft errors on:", "deadlist error message");
assert_contains($res["error"], "deadlist_v4", "deadlist error should mention failing set");
assert_eq($res["blocked_v4"], 1, "deadlist v4 count despite nft failure");
assert_eq($res["blocked_v6"], 0, "deadlist v6 count despite nft failure");
assert_eq($res["entries"], 1, "deadlist entry count despite nft failure");

$calls = read_nft_calls($log);
assert_true(nft_calls_has($calls, '/^nft\|add\|element\|inet\|filter\|deadlist_v4/'), "failing v4 add element was not attempted");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
