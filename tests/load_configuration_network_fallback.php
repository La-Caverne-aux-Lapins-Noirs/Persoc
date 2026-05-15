#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_configuration_detect_network_identity"), "network identity helper missing");

$tmp = mk_tmp_dir("persoc_unit_load_configuration_network_fallback");
$bin = $tmp . "/bin";

install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
if [ "$1" = "route" ] && [ "$2" = "get" ]; then
  echo "8.8.8.8 via 192.168.42.1 dev wlan-test0 uid 0"
  exit 0
fi
if [ "$1" = "-4" ] && [ "$2" = "addr" ] && [ "$3" = "show" ] && [ "$4" = "dev" ]; then
  echo "3: $5: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500"
  echo "    inet 192.168.42.99/24 brd 192.168.42.255 scope global $5"
  exit 0
fi
if [ "$1" = "link" ] && [ "$2" = "show" ] && [ "$3" = "dev" ]; then
  echo "3: $4: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500"
  exit 0
fi
exit 1
SH);

install_mock_cmd($bin, "cat", <<<'SH'
#!/bin/sh
if [ "$1" = "/sys/class/net/wlan-test0/address" ]; then
  echo "AA:BB:CC:DD:EE:01"
  exit 0
fi
exit 1
SH);

$identity = with_path_prefix($bin, function() {
    return persoc_configuration_detect_network_identity();
});

assert_eq($identity["Interface"], "wlan-test0", "interface from route");
assert_eq($identity["IP"], "192.168.42.99", "ip fallback from ip addr");
assert_eq($identity["Mac"], "aa:bb:cc:dd:ee:01", "mac fallback from /sys cat");

install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
exit 1
SH);
install_mock_cmd($bin, "cat", <<<'SH'
#!/bin/sh
exit 1
SH);

$empty = with_path_prefix($bin, function() {
    return persoc_configuration_detect_network_identity();
});
assert_eq($empty, ["Interface" => "", "IP" => "", "Mac" => ""], "empty identity when route is unavailable");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
