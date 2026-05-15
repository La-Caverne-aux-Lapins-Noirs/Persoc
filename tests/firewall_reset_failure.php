#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_reset"), "firewall_reset() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_reset_failure");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
@mkdir($bin, 0755, true);

$logEsc = str_replace("'", "'\"'\"'", $log);
put_exe($bin . "/nft", <<<SH
#!/bin/sh
LOGFILE='$logEsc'
out="nft"
for a in "\$@"; do
  out="\$out|\$a"
done
echo "\$out" >> "\$LOGFILE"
if [ "\$1" = "add" ] && [ "\$2" = "table" ]; then
  exit 7
fi
exit 0
SH);

$res = with_path_prefix($bin, function() {
    return firewall_reset();
});

assert_eq($res["ok"], false, "firewall_reset should report nft failure");
assert_eq($res["commands"], 2, "firewall_reset command count on failure");
assert_contains($res["error"], "nft add table inet filter", "firewall_reset error should name failed command");

$calls = read_nft_calls($log);
assert_eq(count($calls), 2, "firewall_reset should still attempt both commands");
assert_eq($calls[0], "nft|flush|ruleset", "first reset command");
assert_eq($calls[1], "nft|add|table|inet|filter", "second reset command");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
