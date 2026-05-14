#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("send_data"), "send_data() not defined");

$tmp = mk_tmp_dir("persoc_unit_send_data_ssh_failure");
$bin = $tmp . "/bin";
$argsLog = $tmp . "/ssh.args.log";
$stdinDump = $tmp . "/ssh.stdin.dump";
$json = json_encode(["result" => "ok", "answer" => 42], JSON_UNESCAPED_UNICODE);
assert_true(is_string($json), "json_encode failed in test");

$argsLogEsc = str_replace("'", "'\"'\"'", $argsLog);
$stdinEsc = str_replace("'", "'\"'\"'", $stdinDump);
$jsonEsc = str_replace("'", "'\"'\"'", $json);

install_mock_cmd($bin, "ssh", <<<SH
#!/bin/sh
out="ssh"
for a in "\$@"; do
  out="\$out|\$a"
done
echo "\$out" >> '$argsLogEsc'
cat > '$stdinEsc'
printf '%s\n' '$jsonEsc'
exit 255
SH);

$res = with_path_prefix($bin, function() {
    return send_data("example.test", ["command" => "ping"]);
});

assert_eq($res, null, "send_data() must ignore JSON stdout when ssh exits with failure");

$stdin = file_exists($stdinDump) ? file_get_contents($stdinDump) : "";
assert_true(is_string($stdin) && $stdin !== "", "ssh stdin was not dumped");
assert_contains($stdin, '"command":"ping"', "stdin JSON missing command");

rm_rf($tmp);
fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
