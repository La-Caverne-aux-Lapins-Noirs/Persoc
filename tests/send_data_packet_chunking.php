#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("hand_packet"), "hand_packet() not defined");

$packet = hand_packet(["message" => "abcdef"], 5);
assert_true(str_ends_with($packet, "stop" . chr(11) . "\n"), "packet stop marker");

$body = substr($packet, 0, -strlen("stop" . chr(11) . "\n"));
if (str_ends_with($body, "\n"))
    $body = substr($body, 0, -1);
$payload = str_replace("\n", "", $body);
assert_true(str_ends_with($payload, chr(11)), "payload should end with vertical tab");
$json = substr($payload, 0, -1);
$decoded = json_decode($json, true);
assert_eq($decoded, ["message" => "abcdef"], "chunked packet payload should decode");

$defaulted = hand_packet(["x" => "y"], 0);
assert_contains($defaulted, "stop" . chr(11), "non-positive chunk size should still build packet");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
