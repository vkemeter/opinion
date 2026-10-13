<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Delivery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\EmailDeliveryChannel;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Formatting\DataFlattener;

final class EmailDeliveryChannelTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function subjectDataProvider(): iterable
    {
        yield 'short message' => ['[Opinion]', 'Image is cropped.', '[Opinion] [bug/high] Image is cropped.'];
        yield 'whitespace is collapsed' => ['[Opinion]', "Image\n\nis   cropped.", '[Opinion] [bug/high] Image is cropped.'];
        yield 'long message is cut after 60 characters' => [
            '[Opinion]',
            str_repeat('ä', 70),
            '[Opinion] [bug/high] ' . str_repeat('ä', 60) . '…',
        ];
        yield 'empty prefix' => ['', 'Image is cropped.', '[bug/high] Image is cropped.'];
    }

    #[Test]
    #[DataProvider('subjectDataProvider')]
    public function buildSubjectContainsPrefixCategorySeverityAndMessageStart(string $prefix, string $message, string $expected): void
    {
        $subject = new EmailDeliveryChannel(self::createStub(MailerInterface::class), new DataFlattener())->buildSubject(
            new Submission($message, Category::Bug, Severity::High, Source::Frontend, 1, new \DateTimeImmutable()),
            new OpinionConfiguration(true, ['team@example.com'], $prefix, 180),
        );

        self::assertSame($expected, $subject);
    }

    #[Test]
    public function deliverFailsWithoutRecipients(): void
    {
        $result = new EmailDeliveryChannel(self::createStub(MailerInterface::class), new DataFlattener())->deliver(
            new Submission('Image is cropped.', Category::Bug, Severity::High, Source::Frontend, 1, new \DateTimeImmutable()),
            null,
            new OpinionConfiguration(true, [], '[Opinion]', 180),
        );

        self::assertFalse($result->success);
        self::assertSame('email', $result->channel);
    }
}
