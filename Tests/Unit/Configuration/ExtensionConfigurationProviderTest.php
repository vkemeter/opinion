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
    /**
     * Requirement: Email channel (recipients, subjectPrefix)
     */
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

    /**
     * Scenario: Attempt limit
     * Requirement: Rate limit per backend user (default 10, 0 disables)
     */
    #[Test]
    public function getConfigurationReadsRedeliveryAndRateLimit(): void
    {
        $configuration = $this->createSubject(['redeliverMaxAttempts' => '0', 'rateLimit' => '-3'])->getConfiguration();
        self::assertSame(0, $configuration->redeliverMaxAttempts);
        self::assertSame(0, $configuration->rateLimit);

        $defaults = $this->createSubject([])->getConfiguration();
        self::assertSame(5, $defaults->redeliverMaxAttempts);
        self::assertSame(10, $defaults->rateLimit);
    }

    /**
     * Scenario: Out of range
     */
    #[Test]
    public function getConfigurationReadsTheVideoSettings(): void
    {
        $defaults = $this->createSubject([])->getConfiguration();
        self::assertFalse($defaults->videoEnabled);
        self::assertSame(60, $defaults->videoMaxDuration);
        self::assertSame(60 * 160 * 1024, $defaults->videoMaxSize());

        $own = $this->createSubject(['videoEnabled' => '1', 'videoMaxDuration' => '900'])->getConfiguration();
        self::assertTrue($own->videoEnabled);
        self::assertSame(300, $own->videoMaxDuration);
        self::assertSame(300 * 160 * 1024, $own->videoMaxSize());
        self::assertSame(10, $this->createSubject(['videoMaxDuration' => '3'])->getConfiguration()->videoMaxDuration);
    }

    /**
     * Scenario: Default list
     * Scenario: Own list with labels
     * Scenario: Invalid and duplicate entries
     * Scenario: Nothing valid left
     */
    #[Test]
    public function getConfigurationReadsTheCategories(): void
    {
        self::assertSame(['bug' => '', 'content' => '', 'idea' => '', 'other' => ''], $this->createSubject([])->getConfiguration()->categories);
        self::assertSame(
            ['bug' => '', 'question' => 'Question', 'design' => 'Design'],
            $this->createSubject(['categories' => 'bug, question=Question , design=Design'])->getConfiguration()->categories,
        );
        self::assertSame(['bug' => '', 'idea' => ''], $this->createSubject(['categories' => 'bug,Bug Report,bug=Defect,<b>x</b>,idea'])->getConfiguration()->categories);
        self::assertSame(['bug' => '', 'content' => '', 'idea' => '', 'other' => ''], $this->createSubject(['categories' => ' , ,=Label'])->getConfiguration()->categories);
    }

    /**
     * Scenario: Own list
     * Scenario: Two final states
     * Scenario: Initial status cannot be closed
     * Scenario: Reopen status not usable
     */
    #[Test]
    public function getConfigurationResolvesTheStatusRoles(): void
    {
        $defaults = $this->createSubject([])->getConfiguration();
        self::assertSame(['new' => '', 'in_progress' => '', 'closed' => ''], $defaults->statuses);
        self::assertSame('new', $defaults->initialStatus());
        self::assertSame(['closed'], $defaults->closedStatuses);
        self::assertSame('in_progress', $defaults->reopenStatus);

        $own = $this->createSubject([
            'statuses' => 'new, waiting=Waiting for reply, closed, rejected=Rejected',
            'closedStatuses' => 'closed, rejected, unknown',
            'reopenStatus' => 'waiting',
        ])->getConfiguration();
        self::assertSame(['new', 'waiting', 'closed', 'rejected'], $own->statusValues());
        self::assertSame(['closed', 'rejected'], $own->closedStatuses, 'unconfigured closed status ignored');
        self::assertSame('waiting', $own->reopenStatus);
        self::assertTrue($own->isClosed('rejected'));
        self::assertFalse($own->isClosed('removed'), 'a status that is no longer configured counts as open');

        self::assertSame(['closed'], $this->createSubject(['closedStatuses' => 'new,closed'])->getConfiguration()->closedStatuses, 'initial status cannot be closed');
        self::assertSame([], $this->createSubject(['closedStatuses' => ''])->getConfiguration()->closedStatuses, 'no closed status');
        self::assertSame('new', $this->createSubject(['statuses' => 'new,closed'])->getConfiguration()->reopenStatus, 'reopen status not configured');
        self::assertSame('new', $this->createSubject(['reopenStatus' => 'closed'])->getConfiguration()->reopenStatus, 'reopen status closed');
        self::assertSame('open', $this->createSubject(['statuses' => ' , Done!,open=Open,done=Done'])->getConfiguration()->initialStatus());
        self::assertSame(['new', 'in_progress', 'closed'], $this->createSubject(['statuses' => ' , Done!'])->getConfiguration()->statusValues(), 'nothing valid left');
    }

    /**
     * Scenario: Missing recipients
     */
    #[Test]
    public function getConfigurationDropsInvalidRecipients(): void
    {
        $configuration = $this->createSubject(['recipients' => 'not-an-email, team@example.com, '])->getConfiguration();

        self::assertSame(['team@example.com'], $configuration->recipients);
    }

    /**
     * Requirement: Retention cleanup (default retentionDays 180)
     */
    #[Test]
    public function getConfigurationFallsBackToDefaultsForMissingOrInvalidValues(): void
    {
        $configuration = $this->createSubject(['retentionDays' => '-5'])->getConfiguration();

        self::assertTrue($configuration->enabled);
        self::assertSame([], $configuration->recipients);
        self::assertSame('[Opinion]', $configuration->subjectPrefix);
        self::assertSame(180, $configuration->retentionDays);
    }

    /**
     * Scenario: Disabled IP address
     */
    #[Test]
    public function getConfigurationReadsSwitchedOffDetails(): void
    {
        $configuration = $this->createSubject(['collect_system_ipAddress' => '0', 'collect_client_screen' => '1'])->getConfiguration();

        self::assertSame([CollectableField::SystemIpAddress], $configuration->disabledFields);
        self::assertFalse($configuration->collects(CollectableField::SystemIpAddress));
        self::assertTrue($configuration->collects(CollectableField::ClientScreen));
        self::assertTrue($configuration->collects(CollectableField::ClientUrl));
    }

    /**
     * Scenario: Request token
     */
    #[Test]
    public function getConfigurationReadsDeniedUrlParameters(): void
    {
        self::assertSame(['ADMCMD_*', 'session'], $this->createSubject(['deniedUrlParameters' => ' ADMCMD_* , session,'])->getConfiguration()->deniedUrlParameters);
        self::assertSame([], $this->createSubject(['deniedUrlParameters' => ''])->getConfiguration()->deniedUrlParameters);
        self::assertContains('ADMCMD_*', $this->createSubject([])->getConfiguration()->deniedUrlParameters);
    }

    /**
     * Scenario: Custom channel disabled
     */
    #[Test]
    public function getConfigurationReadsDisabledDeliveryChannels(): void
    {
        self::assertSame(['email', 'jira'], $this->createSubject(['disabledDeliveryChannels' => ' email , jira,'])->getConfiguration()->disabledDeliveryChannels);
        self::assertSame([], $this->createSubject([])->getConfiguration()->disabledDeliveryChannels);
    }

    /**
     * Scenario: Extension disabled
     * Scenario: Switched off
     */
    #[Test]
    public function getConfigurationReadsDisabledSwitch(): void
    {
        self::assertFalse($this->createSubject(['enabled' => '0'])->getConfiguration()->enabled);
        self::assertFalse($this->createSubject(['conversation' => '0'])->getConfiguration()->conversationEnabled);
        self::assertTrue($this->createSubject([])->getConfiguration()->conversationEnabled, 'on by default');
    }

    /**
     * Requirement: Retention cleanup (default retentionDays 180)
     */
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
