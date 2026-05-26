<?php
declare(strict_types=1);

/**
 * Build a packet payload for Distrans:
 * - JSON (unicode unescaped) + "\v"
 * - split into chunks
 * - join with "\n"
 * - append "stop\v\n"
 */
function hand_packet(array $data, int $chunkSize = 2048): string
{
    if ($chunkSize <= 0) {
        $chunkSize = 2048;
    }

    $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        $payload = "{}";
    }

    $payload .= "\v";

    $chunks = str_split($payload, $chunkSize);
    $chunks[] = "stop\v\n";

    return implode("\n", $chunks);
}

function persoc_decode_distrans_output(string $out): ?array
{
    $out = trim($out);
    if ($out === "")
        return null;

    $decoded = json_decode($out, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
        return $decoded;

    $lines = preg_split('/\r\n|\r|\n/', $out);
    if (!is_array($lines))
        return null;

    for ($i = count($lines) - 1; $i >= 0; --$i)
    {
        $line = trim($lines[$i]);
        if ($line === "")
            continue;
        $decoded = json_decode($line, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
            return $decoded;
    }

    return null;
}

function send_data(
    string $host,
    array $data,
    string $sshUser = "distrans",
    int $port = 4422,
    string $identityFile = "/root/.ssh/ihk",
): ?array
{
    global $Configuration;

    if (isset($Configuration) && is_array($Configuration) && isset($Configuration["SSH"]) && is_array($Configuration["SSH"]))
    {
        if (isset($Configuration["SSH"]["User"]) && is_string($Configuration["SSH"]["User"]) && trim($Configuration["SSH"]["User"]) !== "")
            $sshUser = trim($Configuration["SSH"]["User"]);
        if (isset($Configuration["SSH"]["Port"]))
            $port = max(1, min(65535, (int)$Configuration["SSH"]["Port"]));
        if (isset($Configuration["SSH"]["IdentityFile"]) && is_string($Configuration["SSH"]["IdentityFile"]) && trim($Configuration["SSH"]["IdentityFile"]) !== "")
            $identityFile = trim($Configuration["SSH"]["IdentityFile"]);
    }

    $stdin = hand_packet($data);

    $args = [
        "ssh",
        "-T",
        "-i", $identityFile,
        "-p", (string)$port,
        "-o", "RequestTTY=no",
        "-o", "BatchMode=yes",
        "-o", "IdentitiesOnly=yes",
        "-o", "UserKnownHostsFile=/dev/null",
        "-o", "StrictHostKeyChecking=no",
        "-o", "LogLevel=ERROR",
        "-o", "ConnectTimeout=5",
        "-o", "ServerAliveInterval=5",
        "-o", "ServerAliveCountMax=1",
        $sshUser . "@" . $host,
    ];

    $cmd = "";
    foreach ($args as $arg) {
        $cmd .= ($cmd === "" ? "" : " ") . escapeshellarg($arg);
    }

    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];

    $proc = @proc_open($cmd, $descriptorspec, $pipes);
    if (!is_resource($proc)) {
        return null;
    }

    $writeOk = fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    if ($writeOk === false) {
        return null;
    }

    if ($exitCode !== 0) {
        return null;
    }

    if (!is_string($out)) {
        return null;
    }

    $out = trim($out);
    if ($out === "") {
        return null;
    }

    $decoded = persoc_decode_distrans_output($out);
    persoc_log("distrans answered $out", true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

