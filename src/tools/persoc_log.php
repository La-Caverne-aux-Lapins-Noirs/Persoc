<?php

function persoc_log_path(): string
{
    global $Configuration;

    $env = getenv("PERSOC_LOG_FILE");
    if (is_string($env) && trim($env) !== "")
        return $env;

    if (
        isset($Configuration)
        && is_array($Configuration)
        && isset($Configuration["LogFile"])
        && is_string($Configuration["LogFile"])
        && trim($Configuration["LogFile"]) !== ""
    )
        return $Configuration["LogFile"];

    return "/var/log/persoc/persoc.log";
}

function persoc_log(string $msg, $only_trace = false): void
{
    global $Configuration;

    $msg = "[".date("Y-m-d H:i:s")."] persoc: $msg\n";
    fwrite(STDERR, $msg);
    if (@$Configuration["Distrans"] && @$Configuration["IP"] && @$Configuration["Mac"] && $only_trace == false)
	send_data($Configuration["Distrans"], [
	    "command" => "persoc_log",
	    "ip" => $Configuration["IP"],
	    "mac" => $Configuration["Mac"],
	    "message" => $msg,
	]);

    $path = persoc_log_path();
    $dir = dirname($path);
    if (!is_dir($dir))
        @mkdir($dir, 0755, true);
    file_put_contents($path, $msg, FILE_APPEND);
}

