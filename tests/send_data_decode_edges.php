#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("hand_packet"), "hand_packet() not defined");
assert_true(function_exists("persoc_decode_distrans_output"), "persoc_decode_distrans_output() not defined");

assert_eq(hand_packet(["x" => 1], 0), hand_packet(["x" => 1], 2048), "non-positive chunk size should fall back to default");
assert_eq(persoc_decode_distrans_output("   \n\t"), null, "blank output should decode to null");
assert_eq(persoc_decode_distrans_output("[]\n"), [], "JSON array output should decode");
assert_eq(persoc_decode_distrans_output("noise\n  \n{\"result\":\"ok\"}\n"), ["result" => "ok"], "decoder should skip trailing blank lines and decode final JSON");
assert_eq(persoc_decode_distrans_output("noise\n{broken json}\n"), null, "decoder should reject output without JSON object/array line");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
