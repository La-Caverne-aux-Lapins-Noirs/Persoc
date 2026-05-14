#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("send_data"), "send_data() not defined");
assert_true(function_exists("persoc_decode_distrans_output"), "persoc_decode_distrans_output() not defined");

$expected = ["result" => "ok", "answer" => 42];
$json = json_encode($expected, JSON_UNESCAPED_UNICODE);
assert_true(is_string($json), "json_encode failed in test");

assert_eq(
    persoc_decode_distrans_output("debug line\n" . $json . "\n"),
    $expected,
    "decode final JSON line"
);
assert_eq(
    persoc_decode_distrans_output("not json\n"),
    null,
    "invalid output returns null"
);

$tmp = mk_tmp_dir("persoc_unit_send_data_multiline");
$bin = $tmp . "/bin";
$argsLog = $tmp . "/ssh.args.log";
$stdinDump = $tmp . "/ssh.stdin.dump";

install_mock_ssh($bin, $argsLog, $stdinDump, "ssh banner before json\n" . $json . "\n");

$res = with_path_prefix($bin, function() {
    return send_data("example.test", ["command" => "ping"]);
});

assert_eq($res, $expected, "send_data should decode final JSON line");

$stdin = file_exists($stdinDump) ? file_get_contents($stdinDump) : "";
assert_true(is_string($stdin) && $stdin !== "", "ssh stdin was not dumped");
assert_contains($stdin, '"command":"ping"', "stdin JSON missing command");
assert_contains($stdin, "stop" . chr(11), "stdin missing stop marker");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . PHP_EOL);
exit(0);
