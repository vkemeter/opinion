<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Storage;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
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

    /**
     * Requirement: Non-public screenshot storage
     */
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

    /**
     * Scenario: Second setup run
     */
    #[Test]
    public function installIsIdempotent(): void
    {
        $installer = $this->get(ScreenshotStorageInstaller::class);

        self::assertSame($installer->install(), $installer->install());
        self::assertSame(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->count('*', 'sys_file_storage', ['name' => ScreenshotStorageLocation::STORAGE_NAME]));
    }

    /**
     * Scenario: Second setup run
     */
    #[Test]
    public function extensionSetupCreatesTheStorageOnce(): void
    {
        // The command authenticates as the CLI user, as bin/typo3 does
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        foreach ([1, 2] as $run) {
            $tester = new CommandTester($this->get(CommandRegistry::class)->get('extension:setup'));
            $tester->execute(['--extension' => ['opinion']]);
            self::assertSame(0, $tester->getStatusCode(), 'run ' . $run . ': ' . $tester->getDisplay());
        }

        $storages = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->select(['is_public'], 'sys_file_storage', ['name' => ScreenshotStorageLocation::STORAGE_NAME])->fetchAllAssociative();
        self::assertCount(1, $storages);
        self::assertSame(0, (int)$storages[0]['is_public']);
        self::assertDirectoryExists(Environment::getVarPath() . '/opinion');
    }

    /**
     * Scenario: File name and folder
     */
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

    /**
     * Scenario: Screenshot through the module
     */
    #[Test]
    public function streamReturnsTheFileInline(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $file = $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());

        $response = $this->get(ScreenshotStorage::class)->stream($file);

        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('inline', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(self::png(), (string)$response->getBody());
    }

    /**
     * Scenario: Screenshot storage missing
     */
    #[Test]
    public function storeFailsWithoutStorage(): void
    {
        $screenshotStorage = $this->get(ScreenshotStorage::class);

        self::assertFalse($screenshotStorage->isAvailable());
        $this->expectException(ScreenshotStorageException::class);
        $screenshotStorage->store(self::png(), 'png', new \DateTimeImmutable());
    }

    /**
     * Scenario: Writing the screenshot fails
     */
    #[Test]
    public function storeFailsWhenTheDatedFolderCannotBeCreated(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        // A file named like the year folder makes createFolder() fail; removed again so no later test trips over it
        $blocker = Environment::getVarPath() . '/opinion/2031';
        touch($blocker);

        try {
            $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable('2031-10-09 12:00:00'));
            self::fail('Expected exception');
        } catch (ScreenshotStorageException) {
            self::assertSame([], glob(Environment::getVarPath() . '/opinion/2031/*'));
        } finally {
            unlink($blocker);
        }
    }

    private static function png(): string
    {
        return base64_decode(self::PNG_BASE64, true);
    }
}
