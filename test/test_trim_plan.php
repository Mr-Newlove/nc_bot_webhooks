<?php
// Standalone unit test for TalkService::planTrim — the pure quota-trim decision
// (trigger gate + how many bytes to free), including the --force bypass and the
// "never trim below target" safety.
//
// No Nextcloud container needed: planTrim() is public static and takes only
// scalars, so loading TalkService.php does not resolve the OCP/OCA classes it
// references in property types and method signatures (PHP checks those lazily,
// only when a property is assigned or a method is called — neither happens here).
//
// Run:  php test/test_trim_plan.php
// Exits non-zero if any check fails.

use OCA\Ncbotwebhooks\Service\TalkService;

$base = dirname(__DIR__);
require $base . '/lib/Service/TalkService.php';

$failures = 0;
function check(string $name, bool $cond): void {
    global $failures;
    echo '  ' . ($cond ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$cond) {
        $failures++;
    }
}

// Quota 1000 → trigger 95% (950), target 85% (850).
$q = 1000;

// 1. Below the trigger (90%), no force → under-trigger, nothing to free.
check('90% under trigger (no force)', TalkService::planTrim($q, 900) === ['reason' => 'under-trigger', 'bytes_to_free' => 0]);

// 2. Over the trigger (96%) → proceed; free used - target = 960 - 850 = 110.
$r = TalkService::planTrim($q, 960);
check('96% over trigger proceeds', $r['reason'] === '' && $r['bytes_to_free'] === 110);

// 3. The point of --force: between target and trigger (90%) normally
//    under-triggers, but force trims straight to the target.
$r = TalkService::planTrim($q, 900, true);
check('force bypasses under-trigger (90%)', $r['reason'] === '' && $r['bytes_to_free'] === 50);

// 4. Force still refuses to trim below the target. At 84% (below the 85%
//    target) even force frees nothing.
check('force does not trim below target (84%)', TalkService::planTrim($q, 840, true) === ['reason' => 'under-target', 'bytes_to_free' => 0]);

// 5. Exactly at the target (85%) → nothing to free, force or not.
check('at target (85%) → under-target', TalkService::planTrim($q, 850, true)['reason'] === 'under-target');

// 6. Just over the target (86%): normal run is under-trigger; forced run
//    proceeds and frees exactly used - target = 10.
check('86% normal is under-trigger', TalkService::planTrim($q, 860)['reason'] === 'under-trigger');
check('86% force proceeds, frees 10', TalkService::planTrim($q, 860, true)['bytes_to_free'] === 10);

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed\n";
    exit(1);
}
echo "OK: all checks passed\n";
exit(0);
