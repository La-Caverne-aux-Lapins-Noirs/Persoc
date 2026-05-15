<?php

if (PHP_SAPI !== 'cli')
{
    fwrite(STDERR, "coverage_report.php is a CLI tool.\n");
    exit(1);
}

if ($argc === 1)
{
    fwrite(STDOUT, "OK " . basename(__FILE__) . "\n");
    exit(0);
}

if ($argc !== 4)
{
    fwrite(STDERR, "usage: php tests/coverage_report.php <raw-dir> <text-report> <html-dir>\n");
    exit(1);
}

$raw_dir = $argv[1];
$text_report = $argv[2];
$html_dir = $argv[3];
$root = dirname(__DIR__);

function cov_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function cov_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cov_file_slug(string $file): string
{
    return preg_replace('/[^A-Za-z0-9_.-]+/', '_', $file) . '.html';
}

function cov_line_status(array $states): string
{
    foreach ($states as $state)
        if ($state > 0)
            return 'covered';

    foreach ($states as $state)
        if ($state === -1 || $state === 0)
            return 'uncovered';

    return 'dead';
}

function cov_percent(int $covered, int $coverable): float
{
    if ($coverable === 0)
        return 100.0;
    return ($covered * 100.0) / $coverable;
}

if (!is_dir($raw_dir))
    cov_fail('coverage raw directory not found: ' . $raw_dir);
if (!is_dir($html_dir) && !mkdir($html_dir, 0770, true))
    cov_fail('cannot create html coverage directory: ' . $html_dir);

$merged = [];
$raw_files = glob(rtrim($raw_dir, '/') . '/*.json');
if ($raw_files === false || count($raw_files) === 0)
    cov_fail('no raw coverage files found in: ' . $raw_dir);

foreach ($raw_files as $raw_file)
{
    $payload = json_decode(file_get_contents($raw_file), true);
    if (!is_array($payload) || !isset($payload['files']) || !is_array($payload['files']))
        cov_fail('invalid raw coverage file: ' . $raw_file);

    foreach ($payload['files'] as $file => $lines)
    {
        if (!isset($merged[$file]))
            $merged[$file] = [];

        foreach ($lines as $line => $state)
        {
            $line = intval($line);
            $state = intval($state);
            if (!isset($merged[$file][$line]))
                $merged[$file][$line] = [];
            $merged[$file][$line][] = $state;
        }
    }
}

ksort($merged);
$summary = [];
$total_coverable = 0;
$total_covered = 0;

foreach ($merged as $file => $lines)
{
    ksort($lines, SORT_NUMERIC);
    $coverable = 0;
    $covered = 0;

    foreach ($lines as $states)
    {
        $status = cov_line_status($states);
        if ($status === 'dead')
            continue;
        $coverable++;
        if ($status === 'covered')
            $covered++;
    }

    $total_coverable += $coverable;
    $total_covered += $covered;
    $summary[$file] = [
        'covered' => $covered,
        'coverable' => $coverable,
        'percent' => cov_percent($covered, $coverable),
    ];
}

uasort($summary, function ($a, $b) {
    if ($a['percent'] === $b['percent'])
        return $a['coverable'] <=> $b['coverable'];
    return $a['percent'] <=> $b['percent'];
});

$text = sprintf(
    "Persoc coverage: %.2f%% (%d/%d executable lines)\n\n",
    cov_percent($total_covered, $total_coverable),
    $total_covered,
    $total_coverable
);
$text .= sprintf("%-58s %9s %12s %10s\n", 'File', 'Coverage', 'Covered', 'Lines');
$text .= str_repeat('-', 93) . "\n";
foreach ($summary as $file => $info)
{
    $text .= sprintf(
        "%-58s %8.2f%% %12d %10d\n",
        strlen($file) > 58 ? substr($file, 0, 55) . '...' : $file,
        $info['percent'],
        $info['covered'],
        $info['coverable']
    );
}

if (file_put_contents($text_report, $text) === false)
    cov_fail('cannot write text coverage report: ' . $text_report);

file_put_contents(dirname($text_report) . '/coverage.json', json_encode([
    'total' => [
        'covered' => $total_covered,
        'coverable' => $total_coverable,
        'percent' => cov_percent($total_covered, $total_coverable),
    ],
    'files' => $summary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$css = "body{font-family:sans-serif;margin:2rem;background:#f8f8f8;color:#222}table{border-collapse:collapse;background:white;width:100%}th,td{padding:.35rem .5rem;border-bottom:1px solid #ddd;text-align:left}th{background:#eee}.num{text-align:right}.covered{background:#d8ffd8}.uncovered{background:#ffd8d8}.dead{background:#eee;color:#777}.plain{background:white}.line{font-family:monospace;white-space:pre}.lineno{width:5rem;color:#777;text-align:right;padding-right:1rem;user-select:none}.bar{height:.75rem;background:#ddd;border-radius:.4rem;overflow:hidden}.bar span{display:block;height:100%;background:#4a8}.low span{background:#c55}.mid span{background:#da5}";

$index = "<!doctype html><html><head><meta charset=\"utf-8\"><title>Persoc coverage</title><style>$css</style></head><body>";
$index .= '<h1>Persoc coverage</h1>';
$index .= sprintf('<p><strong>%.2f%%</strong> covered (%d/%d executable lines).</p>', cov_percent($total_covered, $total_coverable), $total_covered, $total_coverable);
$index .= '<table><thead><tr><th>File</th><th class="num">Coverage</th><th class="num">Covered</th><th class="num">Lines</th><th>Indicator</th></tr></thead><tbody>';

foreach ($summary as $file => $info)
{
    $slug = cov_file_slug($file);
    $class = $info['percent'] < 50.0 ? 'low' : ($info['percent'] < 80.0 ? 'mid' : '');
    $index .= '<tr>';
    $index .= '<td><a href="' . cov_h($slug) . '">' . cov_h($file) . '</a></td>';
    $index .= sprintf('<td class="num">%.2f%%</td><td class="num">%d</td><td class="num">%d</td>', $info['percent'], $info['covered'], $info['coverable']);
    $index .= '<td><div class="bar ' . $class . '"><span style="width:' . cov_h(strval($info['percent'])) . '%"></span></div></td>';
    $index .= '</tr>';

    $full_path = $root . '/' . $file;
    $source = is_file($full_path) ? file($full_path) : [];
    $page = "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . cov_h($file) . "</title><style>$css</style></head><body>";
    $page .= '<h1>' . cov_h($file) . '</h1>';
    $page .= sprintf('<p><strong>%.2f%%</strong> covered (%d/%d executable lines). <a href="index.html">Back</a></p>', $info['percent'], $info['covered'], $info['coverable']);
    $page .= '<table><tbody>';

    $max = count($source);
    for ($i = 1; $i <= $max; ++$i)
    {
        $class = 'plain';
        if (isset($merged[$file][$i]))
            $class = cov_line_status($merged[$file][$i]);
        $page .= '<tr class="' . $class . '"><td class="lineno">' . $i . '</td><td class="line">' . cov_h(rtrim($source[$i - 1], "\r\n")) . '</td></tr>';
    }

    $page .= '</tbody></table></body></html>';
    file_put_contents(rtrim($html_dir, '/') . '/' . $slug, $page);
}

$index .= '</tbody></table></body></html>';
file_put_contents(rtrim($html_dir, '/') . '/index.html', $index);
