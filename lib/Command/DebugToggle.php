<?php

declare(strict_types=1);

namespace OCA\NCdiscordhook\Command;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
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
 *
 * Usage:
 *   # Enable debug
 *   php occ ncdiscordhook:debug:enable
 *
 *   # Disable debug
 *   php occ ncdiscordhook:debug:disable
 *
 *   # Toggle (disable if enabled, enable if disabled)
 *   php occ ncdiscordhook:debug:toggle
 *
 *   # Check current status
 *   php occ ncdiscordhook:debug:status
 */
class DebugToggle extends Command {
    private const APP_ID = 'ncdiscordhook';
    private const DEBUG_KEY = 'debug_enabled';

    private IAppConfig $appConfig;

    public function __construct(IAppConfig $appConfig) {
        parent::__construct();
        $this->appConfig = $appConfig;
    }

    protected function configure(): void {
        $this
            ->setName('ncdiscordhook:debug:toggle')
            ->setDescription('Enable or disable the debug endpoint')
            ->addOption('enable', 'e', InputOption::VALUE_NONE, 'Enable the debug endpoint')
            ->addOption('disable', 'd', InputOption::VALUE_NONE, 'Disable the debug endpoint')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show current debug endpoint status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            $enabled = (bool) $this->appConfig->getValueBool(self::APP_ID, self::DEBUG_KEY, false);
            $io->success($enabled ? 'Debug endpoint is ENABLED' : 'Debug endpoint is DISABLED (default)');
            return Command::SUCCESS;
        }

        if ($input->getOption('enable') && $input->getOption('disable')) {
            $io->error('Cannot use both --enable and --disable at the same time.');
            return Command::INVALID;
        }

        if ($input->getOption('enable')) {
            $this->appConfig->setValueBool(self::APP_ID, self::DEBUG_KEY, true);
            $io->warning('Debug endpoint is now ENABLED. Anyone can access /apps/ncdiscordhook/debug.');
            $io->note('To disable later, run: php occ ncdiscordhook:debug:disable');
            return Command::SUCCESS;
        }

        if ($input->getOption('disable')) {
            $this->appConfig->setValueBool(self::APP_ID, self::DEBUG_KEY, false);
            $io->success('Debug endpoint is now DISABLED.');
            return Command::SUCCESS;
        }

        // No flag: toggle
        $current = (bool) $this->appConfig->getValueBool(self::APP_ID, self::DEBUG_KEY, false);
        $this->appConfig->setValueBool(self::APP_ID, self::DEBUG_KEY, !$current);
        $next = !$current ? 'enabled' : 'disabled';
        $io->success("Debug endpoint is now $next.");
        return Command::SUCCESS;
    }
}
