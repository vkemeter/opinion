<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\ScreenshotType;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Validation\SubmissionValidator;

final class SubmissionValidatorTest extends UnitTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\nrest";
    private const string JPEG = "\xFF\xD8\xFF\xE0rest";

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    #[Test]
    public function validInputYieldsTypedValues(): void
    {
        $result = new SubmissionValidator()->validate([
            ...self::validFields(),
            'message' => "  The teaser image is cropped.  \n",
            'clientInfo' => '{"language":"de"}',
            'context' => '{"pageUid":12}',
        ], $this->upload(self::JPEG));

        self::assertTrue($result->isValid());
        self::assertSame('The teaser image is cropped.', $result->message);
        self::assertSame(Category::Bug, $result->category);
        self::assertSame(Severity::High, $result->severity);
        self::assertSame(Source::Frontend, $result->source);
        self::assertSame(['language' => 'de'], $result->clientInfo);
        self::assertSame(['pageUid' => 12], $result->context);
        self::assertSame(ScreenshotType::Jpeg, $result->screenshot?->type);
        self::assertSame(self::JPEG, $result->screenshot->content);
    }

    #[Test]
    public function screenshotAndJsonFieldsAreOptional(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), null);

        self::assertTrue($result->isValid());
        self::assertNull($result->screenshot);
        self::assertSame([], $result->clientInfo);
        self::assertSame([], $result->context);
    }

    #[Test]
    public function noFileUploadCountsAsNoScreenshot(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), self::failedUpload(UPLOAD_ERR_NO_FILE));

        self::assertTrue($result->isValid());
        self::assertNull($result->screenshot);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidFieldsDataProvider(): iterable
    {
        yield 'message missing' => [['message' => null], 'message', 'required'];
        yield 'message whitespace only' => [['message' => "  \n "], 'message', 'required'];
        yield 'message too short' => [['message' => 'too short'], 'message', 'tooShort'];
        yield 'message too long' => [['message' => str_repeat('a', 5001)], 'message', 'tooLong'];
        yield 'message as array' => [['message' => ['a']], 'message', 'required'];
        yield 'category missing' => [['category' => null], 'category', 'required'];
        yield 'category unknown' => [['category' => 'praise'], 'category', 'invalid'];
        yield 'severity unknown' => [['severity' => 'critical'], 'severity', 'invalid'];
        yield 'source unknown' => [['source' => 'cli'], 'source', 'invalid'];
        yield 'client info no JSON' => [['clientInfo' => '{nope'], 'clientInfo', 'invalid'];
        yield 'client info JSON list' => [['clientInfo' => '[1,2]'], 'clientInfo', 'invalid'];
        yield 'client info too large' => [['clientInfo' => '{"a":"' . str_repeat('a', 65536) . '"}'], 'clientInfo', 'tooLarge'];
        yield 'context no JSON object' => [['context' => '"page"'], 'context', 'invalid'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('invalidFieldsDataProvider')]
    public function invalidFieldsYieldErrorCode(array $override, string $field, string $code): void
    {
        $fields = array_filter([...self::validFields(), ...$override], static fn(mixed $value): bool => $value !== null);

        $result = new SubmissionValidator()->validate($fields, null);

        self::assertFalse($result->isValid());
        self::assertSame([$field => $code], $result->errors);
    }

    #[Test]
    public function messageWithExactlyTheLimitsIsValid(): void
    {
        $validator = new SubmissionValidator();

        self::assertTrue($validator->validate([...self::validFields(), 'message' => str_repeat('ä', 10)], null)->isValid());
        self::assertTrue($validator->validate([...self::validFields(), 'message' => str_repeat('ä', 5000)], null)->isValid());
    }

    #[Test]
    public function screenshotWithImageMediaTypeButOtherContentIsRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), $this->upload('<?php echo 1;', 'image/png'));

        self::assertSame(['screenshot' => 'invalidType'], $result->errors);
    }

    #[Test]
    public function screenshotAboveTenMegabytesIsRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), $this->upload(self::PNG . str_repeat('0', 10 * 1024 * 1024)));

        self::assertSame(['screenshot' => 'tooLarge'], $result->errors);
    }

    #[Test]
    public function screenshotExceedingPhpUploadLimitIsRejectedAsTooLarge(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), self::failedUpload(UPLOAD_ERR_INI_SIZE));

        self::assertSame(['screenshot' => 'tooLarge'], $result->errors);
    }

    #[Test]
    public function failedUploadIsRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), self::failedUpload(UPLOAD_ERR_PARTIAL));

        self::assertSame(['screenshot' => 'uploadFailed'], $result->errors);
    }

    #[Test]
    public function allErrorsAreReportedAtOnce(): void
    {
        $result = new SubmissionValidator()->validate(['category' => 'x'], $this->upload('nope'));

        self::assertSame(
            ['message' => 'required', 'category' => 'invalid', 'severity' => 'required', 'source' => 'required', 'screenshot' => 'invalidType'],
            $result->errors,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function validFields(): array
    {
        return [
            'message' => 'The teaser image is cropped.',
            'category' => 'bug',
            'severity' => 'high',
            'source' => 'frontend',
        ];
    }

    private function upload(string $content, string $mediaType = 'image/jpeg'): UploadedFile
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'opinion');
        file_put_contents($file, $content);
        $this->temporaryFiles[] = $file;

        return new UploadedFile($file, strlen($content), UPLOAD_ERR_OK, 'screenshot', $mediaType);
    }

    private static function failedUpload(int $error): UploadedFileInterface
    {
        $upload = self::createStub(UploadedFileInterface::class);
        $upload->method('getError')->willReturn($error);

        return $upload;
    }
}
