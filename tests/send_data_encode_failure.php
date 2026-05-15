#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("hand_packet"), "hand_packet() not defined");

$packet = hand_packet(["bad" => NAN], 16);
assert_contains($packet, "{}", "hand_packet should fall back to empty JSON object when json_encode fails");
assert_contains($packet, "stop" . chr(11), "hand_packet fallback should still contain stop marker");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
