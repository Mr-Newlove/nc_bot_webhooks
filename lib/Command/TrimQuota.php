<?php

declare(strict_types=1);

namespace OCA\Ncbotwebhooks\Command;

use OCA\Ncbotwebhooks\Service\TalkService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually run the quota-based image trim.
 *
 * The same logic runs automatically after every image upload; this command
 * lets you trigger it on demand and see exactly what it computed, including
 * the individual files it would delete. Use --dry-run to preview without
 * deleting anything.
 *
 * Usage:
 *   # Preview what would be freed (changes nothing)
 *   php occ nc_bot_webhooks:trim --dry-run
 *
 *   # Actually trim and print a report
 *   php occ nc_bot_webhooks:trim
 *
 *   # Force a trim to the target even when below the trigger (clears a
 *   # residual left by a partial, lock-blocked trim)
 *   php occ nc_bot_webhooks:trim --force
 */
class TrimQuota extends Command {
    private const MAX_LISTED_FILES = 50;

    private TalkService $talkService;

    public function __construct(TalkService $talkService) {
        parent::__construct();
        $this->talkService = $talkService;
    }

    protected function configure(): void {
        $this
            ->setName('nc_bot_webhooks:trim')
            ->setDescription('Manually run the quota-based image trim (preview with --dry-run)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be freed without deleting anything')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass the 95% trigger and trim straight to the 85% target (clears a residual left by a partial trim)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $summary = $this->talkService->trimToQuota($dryRun, $force);

        if ($summary['reason'] === 'bot-account-missing') {
            $io->warning("The 'talk-bot' account was not found; nothing to trim.");
            return Command::SUCCESS;
        }

        $mode = $dryRun
            ? ' (dry run — nothing will be deleted)'
            : ($force ? ' (forced — trimming to target regardless of the trigger)' : '');
        $io->title('nc_bot_webhooks quota trim' . $mode);

        $io->writeln('  Bot account:      ' . $summary['bot'] . ($summary['unlimited'] ? '  (no quota — 10 GB default cap)' : ''));
        $io->writeln('  Quota:            ' . $this->humanBytes((int) $summary['quota']));
        $io->writeln('  Used:             ' . $this->humanBytes((int) $summary['used']) . ' (' . $summary['usage_pct'] . '%)');
        $io->writeln('  Trigger / Target: ' . (int) round($summary['trigger_pct'] * 100) . '% / ' . (int) round($summary['target_pct'] * 100) . '%');
        $io->writeln('  Bytes to free:    ' . ($summary['bytes_to_free'] > 0 ? $this->humanBytes((int) $summary['bytes_to_free']) : '—'));
        $io->writeln('  ' . ($dryRun ? 'Would free:' : 'Freed:') . '       '
            . ($summary['bytes_freed'] > 0
                ? $this->humanBytes((int) $summary['bytes_freed']) . ' (' . $summary['files_freed'] . ' file' . ($summary['files_freed'] === 1 ? '' : 's') . ')'
                : '— (nothing to free)'));
        $io->newLine();

        // List the files (dry-run: pending deletion; real run: just deleted).
        $files = $summary['files'] ?? [];
        if ($files !== []) {
            $io->writeln('  ' . ($dryRun ? 'Pending deletion' : 'Deleted') . ' (oldest first) — ' . count($files) . ' file(s):');
            foreach ($files as $i => $f) {
                if ($i >= self::MAX_LISTED_FILES) {
                    $io->writeln('    … and ' . (count($files) - self::MAX_LISTED_FILES) . ' more not shown');
                    break;
                }
                $when = $f['mtime'] > 0 ? date('Y-m-d', (int) $f['mtime']) : 'unknown';
                $io->writeln(sprintf('    %-48s %10s  %s', $this->shorten($f['path'], 48), $this->humanBytes((int) $f['size']), $when));
            }
            $io->newLine();
        }

        switch ($summary['reason']) {
            case 'no-quota':
                $io->warning('The bot account has a non-positive quota; nothing to do.');
                break;
            case 'size-not-computed':
                $io->warning('Folder size not yet computed in the filecache; try again in a moment.');
                break;
            case 'under-trigger':
                $io->success('Account is under the ' . (int) round($summary['trigger_pct'] * 100) . '% trigger — nothing to trim.');
                break;
            case 'under-target':
                $io->note('Already at or below the ' . (int) round($summary['target_pct'] * 100) . '% target — nothing to trim.');
                break;
            case 'busy':
                $io->note('A trim is already running — it will finish on its own; try again in a moment.');
                break;
            case 'no-images-dir':
                $io->note('No images directory yet — nothing to trim.');
                break;
            case 'dry-run':
                if ($summary['bytes_freed'] <= 0) {
                    $io->note('Over the trigger, but the images directory has nothing to free.');
                } else {
                    $io->note('Run without --dry-run to delete the ' . $summary['files_freed'] . ' file(s) above.');
                }
                break;
            case 'trimmed':
                if ($summary['bytes_freed'] <= 0) {
                    $io->note('Over the trigger, but the images directory has nothing to free.');
                } else {
                    $io->success('Trim complete — freed ' . $this->humanBytes((int) $summary['bytes_freed']) . ' across ' . $summary['files_freed'] . ' file(s).');
                }
                break;
            case 'error':
                $io->error('Trim failed: ' . ($summary['error'] ?? 'unknown error'));
                return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function humanBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = -1;
        do {
            $value /= 1024;
            $i++;
        } while ($value >= 1024 && $i < count($units) - 1);
        return number_format($value, 2) . ' ' . $units[$i];
    }

    private function shorten(string $s, int $max): string {
        return strlen($s) <= $max ? $s : '…' . substr($s, -($max - 1));
    }
}
