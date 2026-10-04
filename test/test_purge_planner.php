<?php
// Standalone unit test for OldestFirstPurgePlanner (the quota-trim selector).
// No Nextcloud dependencies. Run:  php test/test_purge_planner.php
// Exits non-zero if any check fails.

use OCA\Ncbotwebhooks\Service\OldestFirstPurgePlanner;

$base = dirname(__DIR__);
require $base . '/lib/Service/OldestFirstPurgePlanner.php';

$failures = 0;
function check(string $name, bool $cond): void {
    global $failures;
    echo '  ' . ($cond ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$cond) {
        $failures++;
    }
}

// 1. bytesToFree <= 0 → nothing deleted.
check(
    'zero bytesToFree deletes nothing',
    OldestFirstPurgePlanner::plan([['path' => 'a', 'size' => 10, 'mtime' => 1]], 0) === []
);

// 2. Empty input → nothing deleted.
check('empty input deletes nothing', OldestFirstPurgePlanner::plan([], 100) === []);

// 3. Oldest-first selection across three distinct mtimes.
$files = [
    ['path' => 'new', 'size' => 100, 'mtime' => 300],
    ['path' => 'old', 'size' => 100, 'mtime' => 100],
    ['path' => 'mid', 'size' => 100, 'mtime' => 200],
];
// Free 150 → delete old(100) + mid(100) = 200, stop before new.
check('oldest-first: deletes old then mid', OldestFirstPurgePlanner::plan($files, 150) === ['old', 'mid']);

// 4. Stops as soon as the threshold is reached.
check('stops at threshold: deletes only old', OldestFirstPurgePlanner::plan($files, 100) === ['old']);

// 5. Deletes everything when the total is smaller than the target.
$all = OldestFirstPurgePlanner::plan($files, 5000);
sort($all);
check('deletes all when total < target', $all === ['mid', 'new', 'old']);

// 6. mtime tie broken by larger size first.
$tie = [
    ['path' => 'small', 'size' => 10, 'mtime' => 500],
    ['path' => 'big',   'size' => 90, 'mtime' => 500],
];
check('tie: larger deleted first', OldestFirstPurgePlanner::plan($tie, 90) === ['big']);

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed\n";
    exit(1);
}
echo "OK: all checks passed\n";
exit(0);
