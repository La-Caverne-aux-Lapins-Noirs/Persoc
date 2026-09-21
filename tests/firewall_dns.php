#!/usr/bin/php
<?php
declare(strict_types=1);
require_once __DIR__ . "/tools.php";

$tmp = mk_tmp_dir("persoc_unit_firewall_dns");
$bin = $tmp . "/bin";
$nftLog = $tmp . "/nft.log";
$dnsLog = $tmp . "/dnsmasq.log";
$csv = $tmp . "/deadlist.csv";
file_put_contents($csv, "x.com,remote\nwww.example.org,remote\n1.2.3.4,remote\n# ignored\n");
install_mock_nft($bin, $nftLog);
install_mock_dnsmasq($bin, $dnsLog);
install_mock_cmd($bin, "id", "#!/bin/sh\n[ \"\$1\" = \"-u\" ] && [ \"\$2\" = \"nobody\" ] && { echo 65534; exit 0; }\nexit 1\n");

global $Configuration;
$Configuration = [
    "DNS" => [
        "Enabled" => true,
        "Port" => 53535,
        "ConfigFile" => $tmp . "/dnsmasq.conf",
        "PidFile" => $tmp . "/dnsmasq.pid",
        "User" => "nobody",
        "Group" => "nogroup",
        "BrowserPolicies" => true,
        "FirefoxPolicy" => $tmp . "/firefox/policies.json",
        "ChromiumPolicy" => $tmp . "/chromium/persoc.json",
        "ChromePolicy" => $tmp . "/chrome/persoc.json",
    ],
];

$res = with_path_prefix($bin, function() use ($csv) { return persoc_dns_apply_deadlist($csv); });
assert_eq($res["ok"] ?? null, true, "DNS guard should apply");
assert_eq($res["domains"] ?? null, 2, "Only hostnames should become DNS lies");
$config = file_get_contents($Configuration["DNS"]["ConfigFile"]);
assert_contains($config, "address=/x.com/", "x.com NXDOMAIN rule missing");
assert_contains($config, "address=/www.example.org/", "example.org NXDOMAIN rule missing");
assert_true(strpos($config, "1.2.3.4") === false, "IP entry must not become a dnsmasq hostname rule");

$calls = read_nft_calls($nftLog);
$joined = implode("\n", $calls);
assert_contains($joined, "persoc_dns", "DNS nft table not programmed");
assert_contains($joined, "meta|skuid|!=|65534|udp|dport|53|redirect|to|:53535", "UDP DNS redirect missing");
assert_contains($joined, "meta|skuid|!=|65534|tcp|dport|53|redirect|to|:53535", "TCP DNS redirect missing");

$ff = json_decode(file_get_contents($Configuration["DNS"]["FirefoxPolicy"]), true);
assert_eq($ff["policies"]["DNSOverHTTPS"]["Enabled"] ?? null, false, "Firefox DoH must be disabled");
assert_eq($ff["policies"]["DNSOverHTTPS"]["Locked"] ?? null, true, "Firefox DoH policy must be locked");
$chromium = json_decode(file_get_contents($Configuration["DNS"]["ChromiumPolicy"]), true);
assert_eq($chromium["DnsOverHttpsMode"] ?? null, "off", "Chromium DoH must be disabled");

persoc_dns_stop($Configuration["DNS"]["PidFile"]);
rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
