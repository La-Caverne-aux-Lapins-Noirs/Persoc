<?php

function persoc_log(string $msg): void
{
    global $Configuration;
    
    $msg = "[".date("Y-m-d H:i:s")."] persoc: $msg\n";
    fwrite(STDERR, $msg);
    if (@$Configuration["Distrans"] && @$Configuration["IP"] && @$Configuration["Mac"])
	send_data($Configuration["Distrans"], [
	    "command" => "persoc_log",
	    "ip" => $Configuration["IP"],
	    "mac" => $Configuration["Mac"],
	    "message" => $msg,
	]);
    file_put_contents("/var/log/persoc/persoc.log", $msg, FILE_APPEND);
}

