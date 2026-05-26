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
    $r = firewall_reset();
    if (!($r["ok"] ?? false))
        persoc_log("startup: firewall_reset failed: " . ($r["error"] ?? "unknown error"));

    persoc_log("startup: get_new_deadlist()");
    $r = get_new_deadlist();
    if (!($r["ok"] ?? false))
    {
        persoc_log("startup: get_new_deadlist failed: " . ($r["error"] ?? "unknown error") . "; applying local deadlist");
        $r = firewall_deadlist($Configuration["Deadlist"]);
        if (!($r["ok"] ?? false))
            persoc_log("startup: firewall_deadlist failed: " . ($r["error"] ?? "unknown error"));
    }

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
            $r = get_new_deadlist();
            if (!($r["ok"] ?? false))
            {
                persoc_log("get_new_deadlist failed: " . ($r["error"] ?? "unknown error") . "; keeping/applying local deadlist");
                $r = firewall_deadlist($Configuration["Deadlist"]);
                if (!($r["ok"] ?? false))
                    persoc_log("firewall_deadlist failed: " . ($r["error"] ?? "unknown error"));
            }
        }

        if ($now >= $nextActivity)
        {
            $nextActivity = $now + $activityEvery;
            users_log_activity();
        }

        if ($now >= $nextIntruders)
        {
            $nextIntruders = $now + $intrudersEvery;

            // Ask IH (via users_expell_intruders) and apply exam firewall accordingly.
            // On communication/error cases, keep the current firewall state: do not
            // silently disable exam mode just because Distrans did not answer.
            $r = users_expell_intruders();
            if (is_array($r) && ($r["ok"] ?? false) === true && array_key_exists("exam", $r))
            {
                $fw = firewall_exam((bool)$r["exam"]);
                if (!($fw["ok"] ?? false))
                    persoc_log("firewall_exam failed: " . ($fw["error"] ?? "unknown error"));
            }
            else
            {
                $err = is_array($r) ? (string)($r["error"] ?? "unknown error") : "invalid return";
                persoc_log("users_expell_intruders failed: " . $err . "; keeping current exam firewall state");
            }
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
