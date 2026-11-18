<?php
declare(strict_types=1);

require_once __DIR__ . '/tools.php';

$tmp = mk_tmp_dir('persoc_upgrade');
$bin = $tmp . '/bin';
mkdir($bin);
putenv('PERSOC_UPGRADE_TEST_DIR=' . $tmp);
put_exe($bin . '/systemctl', <<<'SH'
#!/bin/sh
if [ "$1" = "show" ]; then
    cat "$PERSOC_UPGRADE_TEST_DIR/unit-state"
    exit 0
fi
printf '%s\n' "$*" >> "$PERSOC_UPGRADE_TEST_DIR/starts"
[ ! -f "$PERSOC_UPGRADE_TEST_DIR/start-fails" ]
SH);
put_exe($bin . '/upgrader', <<<'SH'
#!/bin/sh
[ "$#" -eq 1 ] && [ "$1" = "--force" ] || exit 2
printf '%s\n' "$1" >> "$PERSOC_UPGRADE_TEST_DIR/runs"
exit 0
SH);
file_put_contents($tmp . '/unit-state', 'active');

try
{
    with_path_prefix($bin, function () use ($tmp, $bin) {
        $request = ['id' => str_repeat('a', 32), 'expires_at' => time() + 900];
        persoc_upgrade_accept($request, $tmp);
        assert_eq(persoc_upgrade_status($tmp)['status'], 'pending', 'request is persisted before dispatch');
        assert_eq(persoc_upgrade_run($tmp, $bin . '/upgrader'), 0, 'forced upgrade succeeds');
        assert_eq(persoc_upgrade_status($tmp)['status'], 'succeeded', 'completion is durable');
        $starts = file_get_contents($tmp . '/starts');
        persoc_upgrade_accept($request, $tmp);
        assert_eq(file_get_contents($tmp . '/starts'), $starts, 'lost acknowledgement never repeats a completed upgrade');
        persoc_upgrade_run($tmp, $bin . '/upgrader');
        assert_eq(count(file($tmp . '/runs')), 1, 'restarting the worker never repeats a completed upgrade');
        assert_eq(fileperms($tmp . '/upgrade.json') & 0777, 0600, 'upgrade state is private');

        $request['id'] = str_repeat('b', 32);
        persoc_upgrade_accept($request, $tmp);
        $state = persoc_upgrade_read($tmp);
        $state['status'] = 'running';
        persoc_upgrade_write($tmp, $state);
        assert_eq(persoc_upgrade_status($tmp)['status'], 'running', 'daemon restarts preserve a running worker');
        $request['id'] = str_repeat('c', 32);
        persoc_upgrade_accept($request, $tmp);
        assert_eq(persoc_upgrade_read($tmp)['id'], $state['id'], 'concurrent requests cannot replace a running upgrade');
        file_put_contents($tmp . '/unit-state', 'inactive');
        assert_eq(persoc_upgrade_status($tmp)['status'], 'failed', 'interrupted worker reports failure');

        file_put_contents($tmp . '/start-fails', '');
        persoc_upgrade_accept($request, $tmp);
        assert_eq(persoc_upgrade_status($tmp)['status'], 'failed', 'systemd launch failure is reported');
        unlink($tmp . '/start-fails');
        $request['id'] = str_repeat('d', 32);
        persoc_upgrade_accept($request, $tmp);
        put_exe($bin . '/upgrader', "#!/bin/sh\nexit 100\n");
        assert_eq(persoc_upgrade_run($tmp, $bin . '/upgrader'), 100, 'APT failure is propagated');
        assert_eq(persoc_upgrade_status($tmp)['exit_code'], 100, 'APT exit code is durable');

        $runs = file_get_contents($tmp . '/runs');
        persoc_upgrade_accept(['id' => '../reboot', 'expires_at' => time() + 900], $tmp);
        persoc_upgrade_accept(['id' => str_repeat('e', 32), 'expires_at' => time() - 1], $tmp);
        assert_eq(persoc_upgrade_read($tmp)['id'], $request['id'], 'invalid and expired requests are ignored');
        assert_eq(file_get_contents($tmp . '/runs'), $runs, 'rejected requests run no command');

        if (function_exists('pcntl_fork'))
        {
            $request['id'] = str_repeat('f', 32);
            persoc_upgrade_accept($request, $tmp);
            put_exe($bin . '/upgrader', <<<'SH'
#!/bin/sh
printf '%s\n' "$1" >> "$PERSOC_UPGRADE_TEST_DIR/concurrent-runs"
sleep 0.1
SH);
            $children = [];
            for ($i = 0; $i < 2; ++$i)
            {
                $pid = pcntl_fork();
                assert_true($pid !== -1, 'can fork a concurrent worker');
                if ($pid === 0)
                    exit(persoc_upgrade_run($tmp, $bin . '/upgrader'));
                $children[] = $pid;
            }
            foreach ($children as $pid)
            {
                pcntl_waitpid($pid, $code);
                assert_eq(pcntl_wexitstatus($code), 0, 'concurrent worker exits successfully');
            }
            assert_eq(count(file($tmp . '/concurrent-runs')), 1, 'concurrent workers execute the upgrade once');
        }
    });
}
finally
{
    putenv('PERSOC_UPGRADE_TEST_DIR');
    rm_rf($tmp);
}

echo "OK upgrade.php\n";
