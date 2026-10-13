<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Storage\ScreenshotStorageLocation;

final class ScreenshotStorageLocationTest extends UnitTestCase
{
    protected bool $backupEnvironment = true;

    protected function setUp(): void
    {
        parent::setUp();
        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/srv/project',
            '/srv/project/public',
            '/srv/project/var',
            '/srv/project/config',
            '/srv/project/vendor/bin/typo3',
            'UNIX',
        );
    }

    #[Test]
    public function absolutePathIsBelowVarPathOutsideTheWebRoot(): void
    {
        $path = new ScreenshotStorageLocation()->getAbsolutePath();

        self::assertSame('/srv/project/var/opinion/', $path);
        self::assertStringStartsNotWith(Environment::getPublicPath() . '/', $path);
    }

    #[Test]
    public function relativePathPointsFromPublicPathToVarPath(): void
    {
        self::assertSame('../var/opinion/', new ScreenshotStorageLocation()->getPathRelativeToPublicPath());
    }

    #[Test]
    public function matchesRelativeAndAbsoluteConfiguration(): void
    {
        $location = new ScreenshotStorageLocation();

        self::assertTrue($location->matches(['basePath' => '../var/opinion/', 'pathType' => 'relative']));
        self::assertTrue($location->matches(['basePath' => '/srv/project/var/opinion', 'pathType' => 'absolute']));
        self::assertFalse($location->matches(['basePath' => 'fileadmin/', 'pathType' => 'relative']));
        self::assertFalse($location->matches(['basePath' => '../var/opinion/sub/', 'pathType' => 'relative']));
        self::assertFalse($location->matches([]));
    }
}
