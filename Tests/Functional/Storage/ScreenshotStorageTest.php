<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Storage;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;
use Vkemeter\Opinion\Storage\ScreenshotStorageLocation;

final class ScreenshotStorageTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function installCreatesNonPublicStorageBelowVarPath(): void
    {
        $uid = $this->get(ScreenshotStorageInstaller::class)->install();

        $record = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->select(['*'], 'sys_file_storage', ['uid' => $uid])->fetchAssociative();

        self::assertIsArray($record);
        self::assertSame(ScreenshotStorageLocation::STORAGE_NAME, $record['name']);
        self::assertSame(0, (int)$record['is_public']);
        self::assertSame(0, (int)$record['is_default']);
        self::assertDirectoryExists(Environment::getVarPath() . '/opinion');
    }

    #[Test]
    public function installIsIdempotent(): void
    {
        $installer = $this->get(ScreenshotStorageInstaller::class);

        self::assertSame($installer->install(), $installer->install());
        self::assertSame(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->count('*', 'sys_file_storage', ['name' => ScreenshotStorageLocation::STORAGE_NAME]));
    }

    #[Test]
    public function storeWritesFileIntoDatedFolderOfTheNonPublicStorage(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();

        $file = $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable('2026-10-09 12:00:00'));

        self::assertMatchesRegularExpression('#^/2026/10/[0-9a-f-]{36}\.png$#', $file->getIdentifier());
        self::assertFalse($file->getStorage()->isPublic());
        self::assertSame(self::png(), $file->getContents());
        self::assertFileExists(Environment::getVarPath() . '/opinion' . $file->getIdentifier());
    }

    #[Test]
    public function storeFailsWithoutStorage(): void
    {
        $screenshotStorage = $this->get(ScreenshotStorage::class);

        self::assertFalse($screenshotStorage->isAvailable());
        $this->expectException(ScreenshotStorageException::class);
        $screenshotStorage->store(self::png(), 'png', new \DateTimeImmutable());
    }

    private static function png(): string
    {
        return (string)base64_decode(self::PNG_BASE64, true);
    }
}
