#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("load_configuration"), "load_configuration() not defined");

function expect_load_configuration_exception(string $json, string $needle): void
{
    $tmp = mk_tmp_dir("persoc_unit_load_configuration_errors");
    $bin = $tmp . "/bin";
    $config = $tmp . "/persoc.dab";
    file_put_contents($config, "# mocked\n");

    $jsonEsc = str_replace("'", "'\"'\"'", $json);
    install_mock_cmd($bin, "mergeconf", <<<SH
#!/bin/sh
printf '%s\n' '$jsonEsc'
SH);
    install_mock_cmd($bin, "ip", <<<'SH'
#!/bin/sh
exit 1
SH);

    $thrown = false;
    try {
        with_path_prefix($bin, function() use ($config) {
            load_configuration($config);
        });
    } catch (RuntimeException $e) {
        $thrown = true;
        assert_contains($e->getMessage(), $needle, "exception message");
    }

    assert_true($thrown, "expected RuntimeException containing: " . $needle);
    rm_rf($tmp);
}

expect_load_configuration_exception("not json", "cannot load configuration");
expect_load_configuration_exception('{"Custom":[]}', "missing configuration field: Distrans");
expect_load_configuration_exception('{"Distrans":"   "}', "Distrans must be a non-empty string");
expect_load_configuration_exception('{"Distrans":"ih.test","Custom":42}', "Custom must be string or array");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
