#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_parse_duration"), "persoc_parse_duration() not defined");

assert_eq(persoc_parse_duration("."), 0, "dot idle duration");
assert_eq(persoc_parse_duration(""), 0, "empty idle duration");
assert_eq(persoc_parse_duration("42s"), 42, "seconds duration");
assert_eq(persoc_parse_duration("42.9s"), 42, "decimal seconds duration");
assert_eq(persoc_parse_duration("05:07"), 307, "MM:SS duration");
assert_eq(persoc_parse_duration("01:02:03"), 3723, "HH:MM:SS duration");
assert_eq(persoc_parse_duration("2-03:04:05"), 183845, "DD-HH:MM:SS duration");
assert_eq(persoc_parse_duration("not-a-duration"), 0, "invalid duration");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
