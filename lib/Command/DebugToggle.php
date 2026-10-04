<?php

declare(strict_types=1);

namespace OCA\Ncbotwebhooks\Command;

use OCA\Ncbotwebhooks\Service\TalkService;
use OCP\IAppConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enable or disable the debug endpoint.
 *
 * The debug endpoint exposes internal configuration and database state.
 * It is disabled by default and must be explicitly enabled via this command.
 * When enabled it auto-disables after a TTL (default 2 h; override with
 * --ttl, or pass --ttl 0 for no auto-disable) so a forgotten "enable"
 * can't stay open.
 *
 * Usage:
 *   # Enable debug (auto-disables after 2 h by default)
 *   php occ nc_bot_webhooks:debug:toggle --enable
 *
 *   # Enable debug with a short 10-minute TTL (for quick testing)
 *   php occ nc_bot_webhooks:debug:toggle --enable --ttl 600
 *
 *   # Enable debug with no auto-disable
 *   php occ nc_bot_webhooks:debug:toggle --enable --ttl 0
 *
 *   # Disable debug
 *   php occ nc_bot_webhooks:debug:toggle --disable
 *
 *   # Toggle (disable if enabled, enable if disabled)
 *   php occ nc_bot_webhooks:debug:toggle
 *
 *   # Check current status (shows the auto-disable countdown)
 *   php occ nc_bot_webhooks:debug:toggle --status
 */
class DebugToggle extends Command {
    private const APP_ID = 'nc_bot_webhooks';
    private const DEBUG_KEY = 'debug_enabled';
    private const ENABLED_AT_KEY = 'debug_enabled_at';
    private const TTL_KEY = 'debug_ttl';

    private IAppConfig $appConfig;

    public function __construct(IAppConfig $appConfig) {
        parent::__construct();
        $this->appConfig = $appConfig;
    }

    protected function configure(): void {
        $this
            ->setName('nc_bot_webhooks:debug:toggle')
            ->setDescription('Enable or disable the debug endpoint')
            ->addOption('enable', 'e', InputOption::VALUE_NONE, 'Enable the debug endpoint')
            ->addOption('disable', 'd', InputOption::VALUE_NONE, 'Disable the debug endpoint')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show current debug endpoint status')
            ->addOption(
                'ttl',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds the debug endpoint stays enabled before auto-disabling (0 = no auto-disable)',
                (string) TalkService::DEBUG_TTL_DEFAULT_SECONDS
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->showStatus($io);
        }

        if ($input->getOption('enable') && $input->getOption('disable')) {
            $io->error('Cannot use both --enable and --disable at the same time.');
            return Command::INVALID;
        }

        $ttl = max(0, (int) $input->getOption('ttl'));

        if ($input->getOption('enable')) {
            $this->setEnabled($ttl);
            $io->warning('Debug endpoint is now ENABLED. Anyone can access /apps/nc_bot_webhooks/debug.');
            $this->ttlNote($io, $ttl);
            return Command::SUCCESS;
        }

        if ($input->getOption('disable')) {
            $this->appConfig->setValueBool(self::APP_ID, self::DEBUG_KEY, false);
            $io->success('Debug endpoint is now DISABLED.');
            return Command::SUCCESS;
        }

        // No flag: toggle
        $current = (bool) $this->appConfig->getValueBool(self::APP_ID, self::DEBUG_KEY, false);
        if (!$current) {
            $this->setEnabled($ttl);
            $io->success('Debug endpoint is now enabled.');
            $this->ttlNote($io, $ttl);
        } else {
            $this->appConfig->setValueBool(self::APP_ID, self::DEBUG_KEY, false);
            $io->success('Debug endpoint is now disabled.');
        }
        return Command::SUCCESS;
    }

    /**
     * Record that the debug endpoint is on: set the flag and stamp the enable
     * time + TTL window that the lazy /debug check uses to auto-disable it.
     */
    private function setEnabled(int $ttl): void {
        $this->appConfig->setValueBool(self::APP_ID, self::DEBUG_KEY, true);
        $this->appConfig->setValueInt(self::APP_ID, self::ENABLED_AT_KEY, time());
        $this->appConfig->setValueInt(self::APP_ID, self::TTL_KEY, $ttl);
    }

    /**
     * Human-friendly one-liner about how long the endpoint will stay open.
     */
    private function ttlNote(SymfonyStyle $io, int $ttl): void {
        if ($ttl > 0) {
            $io->note(sprintf(
                'Auto-disables in %d seconds (~%d min). Disable sooner: php occ nc_bot_webhooks:debug:toggle --disable',
                $ttl,
                (int) ceil($ttl / 60)
            ));
        } else {
            $io->note('No auto-disable TTL (--ttl 0). Disable manually: php occ nc_bot_webhooks:debug:toggle --disable');
        }
    }

    /**
     * --status: show whether the endpoint is enabled and, if it has a TTL,
     * the auto-disable countdown.
     */
    private function showStatus(SymfonyStyle $io): int {
        $enabled = (bool) $this->appConfig->getValueBool(self::APP_ID, self::DEBUG_KEY, false);
        if (!$enabled) {
            $io->success('Debug endpoint is DISABLED (default)');
            return Command::SUCCESS;
        }

        $enabledAt = $this->appConfig->getValueInt(self::APP_ID, self::ENABLED_AT_KEY, 0);
        $ttl = $this->appConfig->getValueInt(self::APP_ID, self::TTL_KEY, TalkService::DEBUG_TTL_DEFAULT_SECONDS);
        if ($ttl <= 0) {
            $io->success('Debug endpoint is ENABLED (no auto-disable TTL)');
            return Command::SUCCESS;
        }

        $remaining = $ttl - (time() - $enabledAt);
        if ($remaining <= 0) {
            $io->warning('Debug endpoint is ENABLED but its TTL has elapsed — the next request auto-disables it.');
        } else {
            $io->success(sprintf(
                'Debug endpoint is ENABLED (auto-disables in %d seconds, ~%d min)',
                $remaining,
                (int) ceil($remaining / 60)
            ));
        }
        return Command::SUCCESS;
    }
}
