<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Collection\CollectableField;
use Vkemeter\Opinion\Configuration\ExtensionConfigurationProvider;

final class ExtensionConfigurationProviderTest extends UnitTestCase
{
    #[Test]
    public function getConfigurationParsesSettings(): void
    {
        $configuration = $this->createSubject([
            'enabled' => '1',
            'recipients' => ' team@example.com , dev@example.com,team@example.com',
            'subjectPrefix' => ' [Feedback] ',
            'retentionDays' => '30',
        ])->getConfiguration();

        self::assertTrue($configuration->enabled);
        self::assertSame(['team@example.com', 'dev@example.com'], $configuration->recipients);
        self::assertSame('[Feedback]', $configuration->subjectPrefix);
        self::assertSame(30, $configuration->retentionDays);
    }

    #[Test]
    public function getConfigurationDropsInvalidRecipients(): void
    {
        $configuration = $this->createSubject(['recipients' => 'not-an-email, team@example.com, '])->getConfiguration();

        self::assertSame(['team@example.com'], $configuration->recipients);
    }

    #[Test]
    public function getConfigurationFallsBackToDefaultsForMissingOrInvalidValues(): void
    {
        $configuration = $this->createSubject(['retentionDays' => '-5'])->getConfiguration();

        self::assertTrue($configuration->enabled);
        self::assertSame([], $configuration->recipients);
        self::assertSame('[Opinion]', $configuration->subjectPrefix);
        self::assertSame(180, $configuration->retentionDays);
    }

    #[Test]
    public function getConfigurationReadsSwitchedOffDetails(): void
    {
        $configuration = $this->createSubject(['collect_system_ipAddress' => '0', 'collect_client_screen' => '1'])->getConfiguration();

        self::assertSame([CollectableField::SystemIpAddress], $configuration->disabledFields);
        self::assertFalse($configuration->collects(CollectableField::SystemIpAddress));
        self::assertTrue($configuration->collects(CollectableField::ClientScreen));
        self::assertTrue($configuration->collects(CollectableField::ClientUrl));
    }

    #[Test]
    public function getConfigurationReadsDeniedUrlParameters(): void
    {
        self::assertSame(['ADMCMD_*', 'session'], $this->createSubject(['deniedUrlParameters' => ' ADMCMD_* , session,'])->getConfiguration()->deniedUrlParameters);
        self::assertSame([], $this->createSubject(['deniedUrlParameters' => ''])->getConfiguration()->deniedUrlParameters);
        self::assertContains('ADMCMD_*', $this->createSubject([])->getConfiguration()->deniedUrlParameters);
    }

    #[Test]
    public function getConfigurationReadsDisabledSwitch(): void
    {
        self::assertFalse($this->createSubject(['enabled' => '0'])->getConfiguration()->enabled);
    }

    #[Test]
    public function getConfigurationHandlesUnconfiguredExtension(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('', 1));

        $configuration = new ExtensionConfigurationProvider($extensionConfiguration)->getConfiguration();

        self::assertSame([], $configuration->recipients);
        self::assertSame(180, $configuration->retentionDays);
    }

    /**
     * @param array<string, string> $settings
     */
    private function createSubject(array $settings): ExtensionConfigurationProvider
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($settings);

        return new ExtensionConfigurationProvider($extensionConfiguration);
    }
}
