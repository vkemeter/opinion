<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;

final class AccessCheckerTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{bool, bool, array<string, mixed>, bool}>
     */
    public static function accessDataProvider(): iterable
    {
        yield 'admin without TSconfig' => [true, true, [], true];
        yield 'admin with disabled extension' => [false, true, [], false];
        yield 'editor without TSconfig' => [true, false, [], false];
        yield 'editor with enable = 1' => [true, false, ['options.' => ['opinion.' => ['enable' => '1']]], true];
        yield 'editor with enable = 0' => [true, false, ['options.' => ['opinion.' => ['enable' => '0']]], false];
        yield 'editor with enable = 1 and disabled extension' => [false, false, ['options.' => ['opinion.' => ['enable' => '1']]], false];
    }

    /**
     * @param array<string, mixed> $tsConfig
     */
    #[Test]
    #[DataProvider('accessDataProvider')]
    public function hasAccessHonoursAdminFlagTsConfigAndEnableSwitch(bool $enabled, bool $isAdmin, array $tsConfig, bool $expected): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1];
        $backendUser->method('isAdmin')->willReturn($isAdmin);
        $backendUser->method('getTSConfig')->willReturn($tsConfig);

        self::assertSame($expected, $this->createSubject($enabled)->hasAccess($backendUser));
    }

    #[Test]
    public function hasAccessDeniesMissingBackendUser(): void
    {
        self::assertFalse($this->createSubject(true)->hasAccess(null));
    }

    #[Test]
    public function hasAccessDeniesBackendUserWithoutSession(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->user = null;
        $backendUser->method('isAdmin')->willReturn(true);

        self::assertFalse($this->createSubject(true)->hasAccess($backendUser));
    }

    #[Test]
    public function hasValidEmailRequiresAValidAddress(): void
    {
        $subject = $this->createSubject(true);
        $backendUser = self::createStub(BackendUserAuthentication::class);

        $backendUser->user = ['uid' => 1, 'email' => 'editor@example.com'];
        self::assertTrue($subject->hasValidEmail($backendUser));

        $backendUser->user = ['uid' => 1, 'email' => ''];
        self::assertFalse($subject->hasValidEmail($backendUser));

        $backendUser->user = ['uid' => 1, 'email' => 'not-an-address'];
        self::assertFalse($subject->hasValidEmail($backendUser));

        self::assertFalse($subject->hasValidEmail(null));
    }

    private function createSubject(bool $enabled): AccessChecker
    {
        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn(
            new OpinionConfiguration($enabled, ['team@example.com'], '[Opinion]', 180),
        );

        return new AccessChecker($configurationProvider);
    }
}
