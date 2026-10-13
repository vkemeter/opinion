<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Authentication;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Authentication\AdminRequestChecker;

final class AdminRequestCheckerTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function requestWithoutBackendCookieIsNoAdminRequest(): void
    {
        self::assertFalse(new AdminRequestChecker()->isAdminRequest(new ServerRequest('https://example.com/')));
    }

    #[Test]
    public function authenticatedGlobalBackendUserDecides(): void
    {
        $GLOBALS['BE_USER'] = $this->createBackendUser(true);
        self::assertTrue(new AdminRequestChecker()->isAdminRequest(new ServerRequest('https://example.com/')));

        $GLOBALS['BE_USER'] = $this->createBackendUser(false);
        self::assertFalse(new AdminRequestChecker()->isAdminRequest(new ServerRequest('https://example.com/')));
    }

    private function createBackendUser(bool $isAdmin): BackendUserAuthentication
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1];
        $backendUser->method('isAdmin')->willReturn($isAdmin);

        return $backendUser;
    }
}
