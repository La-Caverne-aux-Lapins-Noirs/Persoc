#!/usr/bin/php
<?php
declare(strict_types=1);

require_once __DIR__ . "/tools.php";

assert_true(function_exists("persoc_is_exam_account"), "persoc_is_exam_account() not defined");

assert_eq(persoc_is_exam_account("alice.bunny.exam"), true, "standard exam account");
assert_eq(persoc_is_exam_account("alice.bunny"), false, "normal account is not exam account");
assert_eq(persoc_is_exam_account("alice.bunny.exam.extra"), false, "extra suffix is not exam account");
assert_eq(persoc_is_exam_account("alice..exam"), false, "empty lastname is not exam account");
assert_eq(persoc_is_exam_account(".bunny.exam"), false, "empty firstname is not exam account");
assert_eq(persoc_is_exam_account("alice-bunny.exam"), false, "missing dot before exam suffix");

fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
exit(0);
