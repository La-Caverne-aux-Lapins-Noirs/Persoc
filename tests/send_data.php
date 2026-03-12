#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

$ROOT = realpath(__DIR__ . "/..");
assert_true($ROOT !== false, "Cannot resolve project root");

$src = $ROOT . "/src/tools/send_data.php";
assert_true(file_exists($src), "Missing source file: " . $src);
require_once $src;

assert_true(function_exists("hand_packet"), "hand_packet() not defined");
assert_true(function_exists("send_data"), "send_data() not defined");

$tmp = mk_tmp_dir("persoc_unit_send_data");
$bin = $tmp . "/bin";
$argsLog = $tmp . "/ssh.args.log";
$stdinDump = $tmp . "/ssh.stdin.dump";

$expected = ["ok" => true, "answer" => 42];
$finalJson = json_encode($expected, JSON_UNESCAPED_UNICODE);
assert_true(is_string($finalJson), "json_encode failed in test");

@mkdir($bin, 0755, true);

$argsLogEsc = str_replace("'", "'\"'\"'", $argsLog);
$stdinEsc = str_replace("'", "'\"'\"'", $stdinDump);
$finalJsonEsc = str_replace("'", "'\"'\"'", $finalJson);

$mockSsh = <<<SH
#!/bin/sh
out="ssh"
for a in "\$@"; do
  out="\$out|\$a"
done
echo "\$out" >> '$argsLogEsc'

cat > '$stdinEsc'

printf '%s' '$finalJsonEsc'
exit 0
SH;

install_mock_cmd($bin, "ssh", $mockSsh);

$res = with_path_prefix($bin, function() {
    $data = ["command" => "ping", "x" => 1, "y" => "é"];
    return send_data("example.test", $data);
});

assert_true(is_array($res), "send_data() should return array");
assert_eq($res, $expected, "send_data() should decode a single JSON stdout payload");

// Assert ssh was called
$args = file_exists($argsLog) ? file($argsLog, FILE_IGNORE_NEW_LINES) : [];
assert_true(is_array($args) && count($args) >= 1, "ssh mock was not invoked");

// Check destination
$joined = implode("\n", $args);
assert_contains($joined, "distrans@example.test", "ssh destination missing");

// Assert stdin contains our packet structure (JSON + \\v ... stop\\v)
$stdin = file_exists($stdinDump) ? file_get_contents($stdinDump) : "";
assert_true(is_string($stdin) && $stdin !== "", "ssh stdin was not dumped");
assert_contains($stdin, "\"command\":\"ping\"", "stdin JSON missing command");
assert_contains($stdin, "\v", "stdin missing vertical-tab separator");
assert_contains($stdin, "stop\v", "stdin missing stop marker");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
