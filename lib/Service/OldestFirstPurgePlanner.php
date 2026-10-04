<?php

namespace OCA\Ncbotwebhooks\Service;

/**
 * Pure, dependency-free planning for quota-based image trimming.
 *
 * Given a flat list of candidate files (path, size in bytes, mtime), decide
 * which to delete — oldest first — until at least $bytesToFree bytes have
 * been reclaimed. This is the unit-testable core of the trim logic; it has no
 * Nextcloud imports so it can be exercised standalone (see
 * test/test_purge_planner.php).
 */
class OldestFirstPurgePlanner {

    /**
     * @param array<int, array{path:string,size:int,mtime:int}> $files
     * @return string[] Paths to delete, oldest-first, whose summed size reaches
     *                  at least $bytesToFree (or all of them, if the total is
     *                  smaller than that).
     */
    public static function plan(array $files, int $bytesToFree): array {
        if ($bytesToFree <= 0 || count($files) === 0) {
            return [];
        }

        // Sort oldest-first. On an mtime tie, delete the larger file first so
        // space is reclaimed with fewer deletes.
        usort($files, static function (array $a, array $b): int {
            if ($a['mtime'] === $b['mtime']) {
                return $b['size'] <=> $a['size'];
            }
            return $a['mtime'] <=> $b['mtime'];
        });

        $toDelete = [];
        $freed = 0;
        foreach ($files as $f) {
            if ($freed >= $bytesToFree) {
                break;
            }
            $toDelete[] = $f['path'];
            $freed += $f['size'];
        }

        return $toDelete;
    }
}
