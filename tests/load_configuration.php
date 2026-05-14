#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("load_configuration"), "load_configuration() not defined");

$tmp = mk_tmp_dir("persoc_unit_load_configuration");
$bin = $tmp . "/bin";
$config = $tmp . "/persoc.dab";
file_put_contents($config, "Distrans = \"ih.test\"\n");

install_mock_cmd($bin, "mergeconf", <<<'SH'
#!/bin/sh
/bin/cat <<'JSON'
{
  "Distrans": "ih.test",
  "Custom": ["  repo.test  ", "repo.test", "", "192.168.1.1"],
  "Deadlist": "",
  "LogFile": "",
  "Intervals": {
    "Tick": 0,
    "Activity": "7",
    "Intruders": "8",
    "Deadlist": "9"
  }
}
JSON
SH);

install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
if [ "$1" = "route" ] && [ "$2" = "get" ]; then
  echo "8.8.8.8 via 192.168.1.1 dev eth9 src 192.168.1.50 uid 0"
  exit 0
fi
if [ "$1" = "-4" ] && [ "$2" = "addr" ] && [ "$3" = "show" ]; then
  echo "2: eth9: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500"
  echo "    inet 192.168.1.50/24 brd 192.168.1.255 scope global eth9"
  exit 0
fi
exit 1
SH);

install_mock_cmd($bin, "cat", <<<'SH'
#!/bin/sh
if [ "$1" = "/sys/class/net/eth9/address" ]; then
  echo "aa:bb:cc:dd:ee:ff"
  exit 0
fi
exit 1
SH);

$conf = with_path_prefix($bin, function() use ($config) {
    return load_configuration($config);
});

assert_eq($conf["Distrans"], "ih.test", "Distrans mismatch");
assert_eq($conf["Custom"], ["repo.test", "192.168.1.1"], "Custom normalization mismatch");
assert_eq($conf["Deadlist"], "/etc/persoc/deadlist.csv", "Deadlist default mismatch");
assert_eq($conf["LogFile"], "/var/log/persoc/persoc.log", "LogFile default mismatch");
assert_eq($conf["Intervals"], ["Tick" => 1, "Activity" => 7, "Intruders" => 8, "Deadlist" => 9], "Intervals mismatch");
assert_eq($conf["IP"], "192.168.1.50", "IP mismatch");
assert_eq($conf["Mac"], "aa:bb:cc:dd:ee:ff", "Mac mismatch");

rm_rf($tmp);
fwrite(STDOUT, "OK ".basename(__FILE__)."\n");
exit(0);
