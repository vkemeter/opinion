<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Dialog;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Dialog\DialogConfigurationBuilder;

final class VideoLimitsTest extends UnitTestCase
{
    private const int KB = 1024;

    /**
     * Requirement: Upload limit known in advance
     */
    #[Test]
    public function videoIsOfferedWithinTheServerLimit(): void
    {
        self::assertSame(
            ['uploadLimit' => 60 * 160 * self::KB, 'maxDuration' => 60],
            DialogConfigurationBuilder::videoLimits($this->configuration(true, 60), PHP_INT_MAX),
        );
    }

    /**
     * Requirement: Upload limit known in advance (shortened duration is implementation, see report F12)
     */
    #[Test]
    public function lowerPhpLimitShortensTheRecording(): void
    {
        self::assertSame(
            ['uploadLimit' => 2048 * self::KB, 'maxDuration' => 12],
            DialogConfigurationBuilder::videoLimits($this->configuration(true, 60), 2048 * self::KB),
        );
    }

    /**
     * Scenario: Low PHP limit
     * Scenario: Video disabled
     */
    #[Test]
    public function videoIsNotOfferedBelowTenSecondsOrWhenDisabled(): void
    {
        self::assertNull(DialogConfigurationBuilder::videoLimits($this->configuration(true, 60), 1024 * self::KB), 'upload_max_filesize = 1M');
        self::assertNull(DialogConfigurationBuilder::videoLimits($this->configuration(false, 60), PHP_INT_MAX));
    }

    private function configuration(bool $videoEnabled, int $videoMaxDuration): OpinionConfiguration
    {
        return new OpinionConfiguration(true, [], '', 180, videoEnabled: $videoEnabled, videoMaxDuration: $videoMaxDuration);
    }
}
