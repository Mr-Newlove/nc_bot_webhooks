<?php
// Standalone unit test for TalkService::isDebugExpired — the pure debug-endpoint
// TTL decision (does a lazy /debug check treat the endpoint as expired?).
//
// No Nextcloud container needed: isDebugExpired() is public static and takes
// only ints, so loading TalkService.php does not resolve the OCP/OCA classes it
// references in property types and method signatures (PHP checks those lazily,
// only when a property is assigned or a method is called — neither happens here).
//
// Run:  php test/test_debug_ttl.php
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

// Default TTL 7200 (2 h), enabled at t=1000.
$t = 1000;
$ttl = 7200;

// 1. Just enabled → not expired.
check('just enabled (now=1000)', TalkService::isDebugExpired($t, $ttl, 1000) === false);

// 2. Just before the TTL → still valid.
check('7199s in, before TTL', TalkService::isDebugExpired($t, $ttl, 1000 + 7199) === false);

// 3. Boundary: exactly at the TTL is still valid (stays open for $ttl seconds).
check('exactly at TTL (7200s) still valid', TalkService::isDebugExpired($t, $ttl, 1000 + 7200) === false);

// 4. Just past the TTL → expired.
check('7201s in, past TTL', TalkService::isDebugExpired($t, $ttl, 1000 + 7201) === true);

// 5. TTL 0 = no auto-disable → never expires.
check('ttl 0 never expires', TalkService::isDebugExpired($t, 0, 999999) === false);

// 6. Negative TTL (defensive) = no auto-disable → never expires.
check('negative ttl never expires', TalkService::isDebugExpired($t, -5, 999999) === false);

// 7. 1-second TTL: at 1s still valid, at 2s expired.
check('1s ttl at 1s still valid', TalkService::isDebugExpired($t, 1, 1001) === false);
check('1s ttl at 2s expired', TalkService::isDebugExpired($t, 1, 1002) === true);

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed\n";
    exit(1);
}
echo "OK: all checks passed\n";
exit(0);
