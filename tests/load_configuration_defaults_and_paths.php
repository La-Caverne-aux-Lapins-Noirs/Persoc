#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("load_configuration"), "load_configuration() not defined");

$tmp = mk_tmp_dir("persoc_unit_load_configuration_defaults_paths");
$bin = $tmp . "/bin";
$home = $tmp . "/home";
$work = $tmp . "/work";
$args = $tmp . "/mergeconf.args.log";
@mkdir($home, 0755, true);
@mkdir($work, 0755, true);
file_put_contents($home . "/persoc.dab", "# home config\n");

$argsEsc = str_replace("'", "'\"'\"'", $args);
install_mock_cmd($bin, "mergeconf", <<<SH
#!/bin/sh
echo "mergeconf|"."\$@" >> '$argsEsc'
cat <<'JSON'
{
  "Distrans": "ih.home",
  "Custom": " updates.local ",
  "Deadlist": "   "
}
JSON
SH);

install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
if [ "$1" = "route" ] && [ "$2" = "get" ]; then
  echo "8.8.8.8 via 10.0.0.1 dev eth-home0 src 10.0.0.23 uid 0"
  exit 0
fi
if [ "$1" = "link" ] && [ "$2" = "show" ] && [ "$3" = "dev" ]; then
  echo "2: $4: <UP> mtu 1500"
  echo "    link/ether 02:00:00:00:00:23 brd ff:ff:ff:ff:ff:ff"
  exit 0
fi
exit 1
SH);

$oldHome = getenv("HOME");
$oldServerHome = $_SERVER["HOME"] ?? null;
$oldCwd = getcwd();
putenv("HOME=" . $home);
$_SERVER["HOME"] = $home;
chdir($work);
try {
    $conf = with_path_prefix($bin, function() {
        return load_configuration();
    });
} finally {
    if (is_string($oldCwd)) chdir($oldCwd);
    if ($oldHome === false) putenv("HOME"); else putenv("HOME=" . $oldHome);
    if ($oldServerHome === null) unset($_SERVER["HOME"]); else $_SERVER["HOME"] = $oldServerHome;
}

assert_eq($conf["Distrans"], "ih.home", "home config Distrans");
assert_eq($conf["Custom"], ["updates.local"], "string Custom normalization");
assert_eq($conf["Deadlist"], "/etc/persoc/deadlist.csv", "blank Deadlist default");
assert_eq($conf["Intervals"], ["Tick" => 1, "Activity" => 5, "Intruders" => 5, "Deadlist" => 0], "interval defaults");
assert_true(isset($conf["Activity"]) && is_array($conf["Activity"]), "Activity defaults should exist when missing from config");
assert_eq($conf["Activity"]["Enabled"], true, "Activity.Enabled default");
assert_eq($conf["Activity"]["Debug"], false, "Activity.Debug default");
assert_eq($conf["Activity"]["TTYRecentSeconds"], 120, "Activity.TTYRecentSeconds default");
assert_eq($conf["Activity"]["IdlePenaltySeconds"], 1800, "Activity.IdlePenaltySeconds default");
assert_eq($conf["Activity"]["RecentFileSeconds"], 900, "Activity.RecentFileSeconds default");
assert_eq($conf["Activity"]["FilesystemScanEvery"], 30, "Activity.FilesystemScanEvery default");
assert_eq($conf["Activity"]["MaxScanDepth"], 6, "Activity.MaxScanDepth default");
assert_eq($conf["Activity"]["MaxScanFiles"], 5000, "Activity.MaxScanFiles default");
assert_eq($conf["Activity"]["SuspiciousAfterSeconds"], 900, "Activity.SuspiciousAfterSeconds default");
assert_eq($conf["Activity"]["HomePattern"], "/home/users/%u", "Activity.HomePattern default");
assert_eq($conf["Interface"], "eth-home0", "default path interface");
assert_eq($conf["IP"], "10.0.0.23", "default path IP");
assert_eq($conf["Mac"], "02:00:00:00:00:23", "default path MAC");

$raw = file_get_contents($args);
assert_true(is_string($raw), "mergeconf args log missing");
assert_contains($raw, $home . "/persoc.dab", "load_configuration should select HOME/persoc.dab when no local config exists");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
