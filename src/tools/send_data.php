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

function send_data(
    string $host,
    array $data,
    string $sshUser = "distrans",
    int $port = 4422,
    string $identityFile = "/root/.ssh/ihk",
): ?array
{
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

    if (!is_string($out)) {
        return null;
    }

    $out = trim($out);
    if ($out === "") {
        return null;
    }

    $decoded = json_decode($out, true);
    persoc_log("distrans answered $out", true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return null;
    }

    return $decoded;
}

