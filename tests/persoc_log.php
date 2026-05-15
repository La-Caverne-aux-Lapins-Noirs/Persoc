#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_log"), "persoc_log() not defined");
assert_true(function_exists("persoc_log_path"), "persoc_log_path() not defined");

$tmp = mk_tmp_dir("persoc_unit_persoc_log");
$bin = $tmp . "/bin";
$argsLog = $tmp . "/ssh.args.log";
$stdinDump = $tmp . "/ssh.stdin.dump";
$envLog = $tmp . "/env/persoc.log";
$configLog = $tmp . "/config/persoc.log";

putenv("PERSOC_LOG_FILE=" . $envLog);
global $Configuration;
$Configuration = ["LogFile" => $configLog];

assert_eq(persoc_log_path(), $envLog, "environment log path must take precedence");
persoc_log("env-only message", true);
$envContent = file_exists($envLog) ? file_get_contents($envLog) : "";
assert_true(is_string($envContent), "env log content must be readable");
assert_contains($envContent, "env-only message", "env log should contain first message");

putenv("PERSOC_LOG_FILE=");
assert_eq(persoc_log_path(), $configLog, "configuration log path fallback");

install_mock_ssh($bin, $argsLog, $stdinDump, json_encode(["result" => "ok"], JSON_UNESCAPED_UNICODE));
$Configuration = [
    "Distrans" => "ih.test",
    "IP" => "192.168.42.23",
    "Mac" => "aa:bb:cc:dd:ee:ff",
    "LogFile" => $configLog,
];

with_path_prefix($bin, function() {
    persoc_log("forwarded message", false);
});

$configContent = file_exists($configLog) ? file_get_contents($configLog) : "";
assert_true(is_string($configContent), "config log content must be readable");
assert_contains($configContent, "forwarded message", "config log should contain forwarded message");
assert_contains($configContent, "distrans answered", "config log should contain send_data trace answer");

$stdin = file_exists($stdinDump) ? file_get_contents($stdinDump) : "";
assert_true(is_string($stdin) && $stdin !== "", "ssh stdin was not dumped");
assert_contains($stdin, '"command":"persoc_log"', "persoc_log packet missing command");
assert_contains($stdin, '"ip":"192.168.42.23"', "persoc_log packet missing ip");
assert_contains($stdin, '"mac":"aa:bb:cc:dd:ee:ff"', "persoc_log packet missing mac");
assert_contains($stdin, "forwarded message", "persoc_log packet missing message");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
