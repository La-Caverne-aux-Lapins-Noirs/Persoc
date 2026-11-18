<?php
declare(strict_types=1);

function persoc_upgrade_supported(): bool
{
    return is_executable('/usr/libexec/huile-auto-upgrade')
        && is_readable('/etc/huile/auto-upgrade-packages.list')
        && is_file('/usr/lib/systemd/system/persoc-upgrade.service');
}

function persoc_upgrade_read(string $directory): ?array
{
    $path = $directory . '/upgrade.json';
    if (!file_exists($path))
        return null;
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state))
        throw new RuntimeException('Cannot read the persistent upgrade state');
    return $state;
}

function persoc_upgrade_write(string $directory, array $state): void
{
    $path = $directory . '/upgrade.json';
    $tmp = $path . '.' . bin2hex(random_bytes(6));
    try
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($tmp, $json) !== strlen($json) || !chmod($tmp, 0600) || !rename($tmp, $path))
            throw new RuntimeException('Cannot save the persistent upgrade state');
    }
    finally
    {
        if (is_file($tmp))
            unlink($tmp);
    }
}

function persoc_upgrade_lock(string $directory)
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
        throw new RuntimeException('Cannot create the Persoc state directory');
    $lock = fopen($directory . '/upgrade.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX))
        throw new RuntimeException('Cannot lock the upgrade state');
    return $lock;
}

function persoc_upgrade_status(string $directory = '/var/lib/persoc'): ?array
{
    $state = persoc_upgrade_read($directory);
    if (($state['status'] ?? '') === 'running')
    {
        $output = [];
        exec('systemctl show --property=ActiveState --value persoc-upgrade.service 2>/dev/null', $output, $code);
        if ($code === 0 && in_array(trim(implode('', $output)), ['inactive', 'failed'], true))
        {
            $lock = persoc_upgrade_lock($directory);
            try
            {
                // The worker may have finished between the status read and this lock.
                $state = persoc_upgrade_read($directory);
                if (($state['status'] ?? '') === 'running')
                {
                    $state['status'] = 'failed';
                    $state['error'] = 'Upgrade service was interrupted';
                    persoc_upgrade_write($directory, $state);
                }
            }
            finally
            {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
    if ($state === null)
        return null;
    return array_intersect_key($state, array_flip(['id', 'status', 'exit_code', 'error']));
}

function persoc_upgrade_accept(array $request, string $directory = '/var/lib/persoc'): void
{
    if (!is_string($request['id'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $request['id']) !== 1
        || !is_int($request['expires_at'] ?? null)
        || $request['expires_at'] <= time())
        return;

    $lock = persoc_upgrade_lock($directory);
    try
    {
        $state = persoc_upgrade_read($directory);
        if (($state['id'] ?? '') === $request['id'] && ($state['status'] ?? '') !== 'pending')
            return;
        if (($state['id'] ?? '') !== $request['id'] && ($state['status'] ?? '') === 'running')
            return;
        $state = ['id' => $request['id'], 'status' => 'pending', 'expires_at' => $request['expires_at']];
        persoc_upgrade_write($directory, $state);
        $output = [];
        exec('systemctl --no-block start persoc-upgrade.service 2>&1', $output, $code);
        if ($code !== 0)
        {
            $state['status'] = 'failed';
            $state['exit_code'] = $code;
            $state['error'] = substr(implode("\n", $output), 0, 500);
            persoc_upgrade_write($directory, $state);
        }
    }
    finally
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function persoc_upgrade_run(string $directory = '/var/lib/persoc',
    string $upgrader = '/usr/libexec/huile-auto-upgrade'): int
{
    $lock = persoc_upgrade_lock($directory);
    try
    {
        $state = persoc_upgrade_read($directory);
        if (($state['status'] ?? '') !== 'pending')
            return 0;
        if ($state['expires_at'] <= time())
        {
            $state['status'] = 'failed';
            $state['error'] = 'Upgrade request expired before execution';
            persoc_upgrade_write($directory, $state);
            return 1;
        }
        $state['status'] = 'running';
        persoc_upgrade_write($directory, $state);
    }
    finally
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    passthru(escapeshellarg($upgrader) . ' --force', $code);
    $lock = persoc_upgrade_lock($directory);
    try
    {
        $state['status'] = $code === 0 ? 'succeeded' : 'failed';
        $state['exit_code'] = $code;
        persoc_upgrade_write($directory, $state);
    }
    finally
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $code;
}
