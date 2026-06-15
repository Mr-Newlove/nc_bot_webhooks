<?php

namespace OCA\Ncbotwebhooks\Test;

use OCA\Ncbotwebhooks\NavigationProvider;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NavigationProvider settings link.
 *
 * Tests that the navigation link is only shown to admin users.
 */
class NavigationProviderTest extends TestCase {
    /**
     * Build a NavigationProvider with all dependencies mocked.
     */
    private function makeNavigationProvider(
        IURLGenerator $urlGenerator,
        IUserSession $userSession,
        IConfig $config,
        IL10N $l10n,
    ): NavigationProvider {
        return new NavigationProvider(
            $urlGenerator,
            $userSession,
            $config,
            $l10n,
        );
    }

    // =========================================================================
    // Non-admin paths
    // =========================================================================

    public function testGetNavigationReturnsEmptyForNullUser(): void {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')
            ->willReturn(null);
        $config = $this->createMock(IConfig::class);
        $l10n = $this->createMock(IL10N::class);

        $provider = $this->makeNavigationProvider($urlGenerator, $userSession, $config, $l10n);
        $nav = $provider->getNavigation();

        $this->assertEquals([], $nav);
    }

    public function testGetNavigationReturnsEmptyForNonAdminUser(): void {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('isAdmin')
            ->willReturn(false);
        $userSession->method('getUser')
            ->willReturn($user);

        $config = $this->createMock(IConfig::class);
        $l10n = $this->createMock(IL10N::class);

        $provider = $this->makeNavigationProvider($urlGenerator, $userSession, $config, $l10n);
        $nav = $provider->getNavigation();

        $this->assertEquals([], $nav);
    }

    // =========================================================================
    // Admin path
    // =========================================================================

    public function testGetNavigationReturnsLinkForAdminUser(): void {
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRoute')
            ->willReturn('https://example.com/settings/admin');
        $urlGenerator->method('imagePath')
            ->willReturn('https://example.com/apps/nc_bot_webhooks/app.svg');

        $userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('isAdmin')
            ->willReturn(true);
        $userSession->method('getUser')
            ->willReturn($user);

        $config = $this->createMock(IConfig::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')
            ->willReturnCallback(function ($text) {
                return $text;
            });

        $provider = $this->makeNavigationProvider($urlGenerator, $userSession, $config, $l10n);
        $nav = $provider->getNavigation();

        $this->assertNotEmpty($nav);
        $this->assertCount(1, $nav);

        $item = $nav[0];
        $this->assertEquals('nc_bot_webhooks', $item['id']);
        $this->assertEquals('nc_bot_webhooks', $item['app_id']);
        $this->assertEquals('settings', $item['type']);
        $this->assertEquals('https://example.com/settings/admin', $item['href']);
        $this->assertEquals('https://example.com/apps/nc_bot_webhooks/app.svg', $item['icon']);
        $this->assertEquals(0, $item['order']);
    }
}
