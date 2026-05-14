<?php

function firewall_reset(): array
{
    $cmds = [
        "nft flush ruleset",
        "nft add table inet filter",
    ];

    $ok = true;
    $errors = [];

    foreach ($cmds as $cmd)
    {
        $ret = 0;
        @system($cmd, $ret);
        if ($ret !== 0)
        {
            $ok = false;
            $errors[] = $cmd;
        }
    }

    return [
        "ok" => $ok,
        "error" => $ok ? "" : ("nft errors on: " . implode(" | ", $errors)),
        "commands" => count($cmds),
    ];
}
