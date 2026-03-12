<?php
declare(strict_types=1);

// Jason Brillante "Damdoshi"
// Pentacle Technologie 2008-2025
// Hanged Bunny Studio 2014-2025
// EFRITS SAS 2025
//
// Persoc (daemon bootstrap + main loop)

foreach (glob(__DIR__."/*/*.php") as $file)
    require_once ($file);

$Configuration = null;

// -----------------------------------------------------------------------------
// Signals
// -----------------------------------------------------------------------------

$GLOBALS["PERSOC_STOP"] = false;

function persoc_install_signal_handlers(): void
{
    if (!function_exists("pcntl_signal"))
        return;

    pcntl_signal(SIGTERM, function() { $GLOBALS["PERSOC_STOP"] = true; });
    pcntl_signal(SIGINT,  function() { $GLOBALS["PERSOC_STOP"] = true; });
}

function persoc_pump_signals(): void
{
    if (function_exists("pcntl_signal_dispatch"))
        pcntl_signal_dispatch();
}

function persoc_should_stop(): bool
{
    return (bool)$GLOBALS["PERSOC_STOP"];
}

// -----------------------------------------------------------------------------
// Main
// -----------------------------------------------------------------------------

try
{
    $Configuration = load_configuration();
    $GLOBALS["Configuration"] = $Configuration; // required by modules

    persoc_install_signal_handlers();

    // Startup: reset + deadlist
    persoc_log("startup: firewall_reset()");
    firewall_reset();

    persoc_log("startup: firewall_deadlist()");
    firewall_deadlist($Configuration["Deadlist"]);

    $tick = (int)$Configuration["Intervals"]["Tick"];
    $activityEvery = (int)$Configuration["Intervals"]["Activity"];
    $intrudersEvery = (int)$Configuration["Intervals"]["Intruders"];
    $deadlistEvery = (int)$Configuration["Intervals"]["Deadlist"];

    persoc_log("running: tick={$tick}s activity={$activityEvery}s intruders={$intrudersEvery}s deadlist={$deadlistEvery}s");

    $nextActivity = 0;
    $nextIntruders = 0;
    $nextDeadlist = 0;

    while (!persoc_should_stop())
    {
        persoc_pump_signals();
        $now = time();

        if ($deadlistEvery > 0 && $now >= $nextDeadlist)
        {
            $nextDeadlist = $now + $deadlistEvery;
            firewall_deadlist($Configuration["Deadlist"]);
        }

        if ($now >= $nextActivity)
        {
            $nextActivity = $now + $activityEvery;
            users_log_activity();
        }

        if ($now >= $nextIntruders)
        {
            $nextIntruders = $now + $intrudersEvery;

            // Ask IH (via users_expell_intruders) and apply exam firewall accordingly
            $r = users_expell_intruders();
            $exam = is_array($r) ? (bool)($r["exam"] ?? false) : false;
            firewall_exam($exam);
        }

        sleep($tick);
    }

    persoc_log("stopping.");
    exit(0);
}
catch (Throwable $e)
{
    persoc_log("FATAL: " . $e->getMessage());
    exit(2);
}
