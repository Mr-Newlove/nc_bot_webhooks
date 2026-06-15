<?php

namespace OCA\Ncbotwebhooks\Test;

use OCA\Ncbotwebhooks\Command\DebugToggle;
use OCP\AppConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Input\InputOption;

/**
 * Unit tests for DebugToggle OCC command.
 *
 * Tests --status, --enable, --disable, toggle, and --enable/--disable conflict.
 */
class DebugToggleTest extends TestCase {
    private const APP_ID = 'nc_bot_webhooks';
    private const DEBUG_KEY = 'debug_enabled';

    private function makeCommand(IAppConfig $appConfig): DebugToggle {
        return new DebugToggle($appConfig);
    }

    // =========================================================================
    // --status
    // =========================================================================

    public function testStatusReturnsDisabledByDefault(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(false);

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput(['--status' => true]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('DISABLED', $output->fetch());
    }

    public function testStatusReturnsEnabledWhenSet(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(true);

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput(['--status' => true]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('ENABLED', $output->fetch());
    }

    // =========================================================================
    // --enable
    // =========================================================================

    public function testEnableSetsValueToTrue(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(false);
        $appConfig->expects($this->once())
            ->method('setValueBool')
            ->with(self::APP_ID, self::DEBUG_KEY, true);

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput(['--enable' => true]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('ENABLED', $output->fetch());
    }

    // =========================================================================
    // --disable
    // =========================================================================

    public function testDisableSetsValueToFalse(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(true);
        $appConfig->expects($this->once())
            ->method('setValueBool')
            ->with(self::APP_ID, self::DEBUG_KEY, false);

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput(['--disable' => true]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('DISABLED', $output->fetch());
    }

    // =========================================================================
    // Toggle (no flags)
    // =========================================================================

    public function testToggleEnablesWhenDisabled(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(false);
        $appConfig->expects($this->once())
            ->method('setValueBool')
            ->with(self::APP_ID, self::DEBUG_KEY, true);

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('enabled', $output->fetch());
    }

    public function testToggleDisablesWhenEnabled(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(true);
        $appConfig->expects($this->once())
            ->method('setValueBool')
            ->with(self::APP_ID, self::DEBUG_KEY, false);

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(0, $result);
        $this->assertStringContainsString('disabled', $output->fetch());
    }

    // =========================================================================
    // Conflict: --enable + --disable
    // =========================================================================

    public function testEnableAndDisableTogetherReturnsInvalid(): void {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueBool')
            ->willReturn(false);
        // Should NOT be called when both flags are set
        $appConfig->expects($this->never())
            ->method('setValueBool');

        $command = $this->makeCommand($appConfig);
        $input = new ArrayInput(['--enable' => true, '--disable' => true]);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertEquals(1, $result); // Command::INVALID
        $this->assertStringContainsString('Cannot use both', $output->fetch());
    }
}
