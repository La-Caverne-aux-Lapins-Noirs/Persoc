<?php

/*
** Lightweight per-test coverage collector for Persoc.
** Loaded with php -d auto_prepend_file=tests/coverage_prepend.php.
*/

if (PHP_SAPI !== 'cli')
    return;

$coverage_file = getenv('PERSOC_COVERAGE_FILE');
if ($coverage_file === false || $coverage_file === '')
    return;

if (!function_exists('xdebug_start_code_coverage'))
{
    fwrite(STDERR, "Xdebug coverage is not available.\n");
    exit(1);
}

$coverage_root = realpath(getcwd());
if ($coverage_root === false)
{
    fwrite(STDERR, "Cannot determine coverage root.\n");
    exit(1);
}

$src_root = $coverage_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

function persoc_coverage_starts_with(string $haystack, string $needle): bool
{
    return substr($haystack, 0, strlen($needle)) === $needle;
}

function persoc_coverage_relative_path(string $path, string $root): string
{
    $path = str_replace('\\', '/', $path);
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

    if (persoc_coverage_starts_with($path, $root))
        return substr($path, strlen($root));
    return $path;
}

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

register_shutdown_function(function () use ($coverage_file, $coverage_root, $src_root) {
    $raw = xdebug_get_code_coverage();
    xdebug_stop_code_coverage(false);

    $files = [];
    foreach ($raw as $file => $lines)
    {
        $real = realpath($file);
        if ($real === false)
            continue;
        if (!persoc_coverage_starts_with($real, $src_root))
            continue;
        if (substr($real, -4) !== '.php')
            continue;

        $relative = persoc_coverage_relative_path($real, $coverage_root);
        $normalized = [];
        foreach ($lines as $line => $state)
            $normalized[strval($line)] = intval($state);
        ksort($normalized, SORT_NUMERIC);
        $files[$relative] = $normalized;
    }

    ksort($files);
    $dir = dirname($coverage_file);
    if (!is_dir($dir) && !mkdir($dir, 0770, true))
    {
        fwrite(STDERR, "Cannot create coverage directory: $dir\n");
        exit(1);
    }

    $payload = [
        'test' => $_SERVER['SCRIPT_FILENAME'] ?? '',
        'files' => $files,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($coverage_file, $json . "\n") === false)
    {
        fwrite(STDERR, "Cannot write coverage file: $coverage_file\n");
        exit(1);
    }
});
