#!/usr/bin/php
<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_parse_start_at"), "persoc_parse_start_at() not defined");

assert_eq(persoc_parse_start_at(1700000123), 1700000123, "integer timestamp");
assert_eq(persoc_parse_start_at("1700000456"), 1700000456, "numeric string timestamp");
assert_eq(persoc_parse_start_at("2026-05-14 12:34:56 UTC"), strtotime("2026-05-14 12:34:56 UTC"), "strtotime-compatible date");
assert_eq(persoc_parse_start_at(""), null, "empty string");
assert_eq(persoc_parse_start_at("not a date at all"), null, "invalid date string");
assert_eq(persoc_parse_start_at(null), null, "null input");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
