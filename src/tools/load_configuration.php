<?php

function load_configuration(string $conf_file = ""): array
{
    if ($conf_file === "")
    {
        if (file_exists("./persoc.dab"))
            $conf_file = "./persoc.dab";
        else if (isset($_SERVER["HOME"]) && file_exists($_SERVER["HOME"] . "/persoc.dab"))
            $conf_file = $_SERVER["HOME"] . "/persoc.dab";
        else
            $conf_file = "/etc/persoc/persoc.dab";
    }

    $conf_file = escapeshellarg($conf_file);
    $raw = shell_exec("mergeconf -i " . $conf_file . " -of .json --resolve 2>/dev/null");
    $conf = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($conf))
        throw new RuntimeException("cannot load configuration via mergeconf");

    // Mandatory fields
    foreach (["Distrans"] as $k)
    {
        if (!array_key_exists($k, $conf))
            throw new RuntimeException("missing configuration field: " . $k);
    }
    if (!is_string($conf["Distrans"]) || trim($conf["Distrans"]) === "")
        throw new RuntimeException("Distrans must be a non-empty string");

    // Normalize allowlists
    foreach (["Custom"] as $k)
    {
        if (!array_key_exists($k, $conf))
        {
            $conf[$k] = [];
            continue;
        }

        if (is_string($conf[$k]))
            $conf[$k] = [trim($conf[$k])];
        if (!is_array($conf[$k]))
            throw new RuntimeException("$k must be string or array");

        $out = [];
        foreach ($conf[$k] as $v)
        {
            if (!is_string($v))
                continue;
            $v = trim($v);
            if ($v !== "")
                $out[] = $v;
        }
        $conf[$k] = array_values(array_unique($out));
    }

    // Optional: deadlist file
    if (!isset($conf["Deadlist"]) || !is_string($conf["Deadlist"]) || trim($conf["Deadlist"]) === "")
        $conf["Deadlist"] = "/etc/persoc/deadlist.csv";

    // Intervals defaults are centralized here
    if (!isset($conf["Intervals"]) || !is_array($conf["Intervals"]))
        $conf["Intervals"] = [];

    if (!isset($conf["Intervals"]["Tick"]))      $conf["Intervals"]["Tick"] = 1;
    if (!isset($conf["Intervals"]["Activity"]))  $conf["Intervals"]["Activity"] = 5;
    if (!isset($conf["Intervals"]["Intruders"])) $conf["Intervals"]["Intruders"] = 5;
    if (!isset($conf["Intervals"]["Deadlist"]))  $conf["Intervals"]["Deadlist"] = 0;

    $conf["Intervals"]["Tick"]      = max(1, (int)$conf["Intervals"]["Tick"]);
    $conf["Intervals"]["Activity"]  = max(1, (int)$conf["Intervals"]["Activity"]);
    $conf["Intervals"]["Intruders"] = max(1, (int)$conf["Intervals"]["Intruders"]);
    $conf["Intervals"]["Deadlist"]  = max(0, (int)$conf["Intervals"]["Deadlist"]);

    $iface = trim(shell_exec("ip route get 8.8.8.8 | awk '{print \$5; exit}'"));
    $ip = trim(shell_exec("ip -4 addr show $iface | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}'"));
    $mac = trim(shell_exec("cat /sys/class/net/$iface/address"));
    $conf["IP"] = $ip;
    $conf["Mac"] = $mac;
    
    return $conf;
}
