#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("firewall_exam"), "firewall_exam() not defined");

$tmp = mk_tmp_dir("persoc_unit_firewall_exam_disable");
$bin = $tmp . "/bin";
$log = $tmp . "/nft.calls.log";
@mkdir($bin, 0755, true);

$logEsc = str_replace("'", "'\"'\"'", $log);
$mock = <<<SH
#!/bin/sh
LOGFILE='$logEsc'

out="nft"
for a in "\$@"; do
  out="\$out|\$a"
done
echo "\$out" >> "\$LOGFILE"

if [ "\$1" = "-a" ] && [ "\$2" = "list" ] && [ "\$3" = "chain" ] && [ "\$4" = "inet" ] && [ "\$5" = "filter" ] && [ "\$6" = "output" ]; then
  echo "table inet filter {"
  echo "  chain output {"
  echo "    type filter hook output priority filter; policy accept;"
  echo "    jump exam_out # handle 42"
  echo "  }"
  echo "}"
  exit 0
fi

exit 0
SH;
put_exe($bin . "/nft", $mock);

global $Configuration;
$Configuration = [
    "Distrans" => "10.10.0.10",
    "Custom" => [],
];

$res = with_path_prefix($bin, function() {
    return firewall_exam(false);
});

assert_true(is_array($res), "firewall_exam(false) should return array");
assert_eq($res["ok"], true, "firewall_exam(false) ok");
assert_eq($res["mode"], "normal", "firewall_exam(false) mode");
assert_eq($res["graphical_users"], 0, "firewall_exam(false) graphical users");
assert_eq($res["exam_uids"], 0, "firewall_exam(false) exam uids");

$calls = read_nft_calls($log);
assert_true(count($calls) > 0, "No nft calls were recorded");

assert_true(nft_calls_has($calls, '/^nft\|add\|table\|inet\|filter\b/'), "Missing base table creation");
assert_true(nft_calls_has($calls, '/^nft\|add\|chain\|inet\|filter\|output\b/'), "Missing output chain creation");
assert_true(nft_calls_has($calls, '/^nft\|add\|chain\|inet\|filter\|exam_out\b/'), "Missing exam_out chain creation");
assert_true(nft_calls_has($calls, '/^nft\|-a\|list\|chain\|inet\|filter\|output\b/'), "Missing output chain handle lookup");
assert_true(nft_calls_has($calls, '/^nft\|delete\|rule\|inet\|filter\|output\|handle\|42\b/'), "Missing jump rule deletion by handle");

$joined = implode("\n", $calls);
assert_true(strpos($joined, "|jump|exam_out") === false, "normal mode must not add jump exam_out rule");
assert_true(strpos($joined, "|drop") === false, "normal mode must not add drop rule");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
