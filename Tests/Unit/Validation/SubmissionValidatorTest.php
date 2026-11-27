<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Domain\ScreenshotType;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\VideoType;
use Vkemeter\Opinion\Validation\SubmissionValidator;

final class SubmissionValidatorTest extends UnitTestCase
{
    private const string PNG = "\x89PNG\r\n\x1a\nrest";
    private const string JPEG = "\xFF\xD8\xFF\xE0rest";
    private const string MP4 = "\x00\x00\x00\x24ftypisom\x00\x00\x02\x00rest";
    private const string WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87rest";
    private const string MATROSKA = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\x82\x88matroska\x42\x87rest";
    private const int VIDEO_MAX_BYTES = 1000;
    private const array CATEGORIES = ['bug', 'content', 'idea', 'other'];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Requirement: Input validation
     */
    #[Test]
    public function validInputYieldsTypedValues(): void
    {
        $result = new SubmissionValidator()->validate([
            ...self::validFields(),
            'message' => "  The teaser image is cropped.  \n",
            'clientInfo' => '{"language":"de"}',
            'context' => '{"pageUid":12}',
        ], $this->upload(self::JPEG), self::CATEGORIES);

        self::assertTrue($result->isValid());
        self::assertSame('The teaser image is cropped.', $result->message);
        self::assertSame('bug', $result->category);
        self::assertSame(Severity::High, $result->severity);
        self::assertSame(Source::Frontend, $result->source);
        self::assertSame(['language' => 'de'], $result->clientInfo);
        self::assertSame(['pageUid' => 12], $result->context);
        self::assertSame(ScreenshotType::Jpeg, $result->screenshot?->type);
        self::assertSame(self::JPEG, $result->screenshot->content);
    }

    /**
     * Requirement: Input validation (clientInfo and context optional)
     */
    #[Test]
    public function screenshotAndJsonFieldsAreOptional(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), null, self::CATEGORIES);

        self::assertTrue($result->isValid());
        self::assertNull($result->screenshot);
        self::assertSame([], $result->clientInfo);
        self::assertSame([], $result->context);
    }

    /**
     * Requirement: Screenshot upload validation (optional)
     */
    #[Test]
    public function noFileUploadCountsAsNoScreenshot(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), self::failedUpload(UPLOAD_ERR_NO_FILE), self::CATEGORIES);

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
        yield 'context JSON list' => [['context' => '[12]'], 'context', 'invalid'];
        yield 'context too large' => [['context' => '{"a":"' . str_repeat('a', 65536) . '"}'], 'context', 'tooLarge'];
    }

    /**
     * Scenario: Message too short
     * Scenario: Unknown category
     * Scenario: Context is a JSON list
     *
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('invalidFieldsDataProvider')]
    public function invalidFieldsYieldErrorCode(array $override, string $field, string $code): void
    {
        $fields = array_filter([...self::validFields(), ...$override], static fn(mixed $value): bool => $value !== null);

        $result = new SubmissionValidator()->validate($fields, null, self::CATEGORIES);

        self::assertFalse($result->isValid());
        self::assertSame([$field => $code], $result->errors);
    }

    /**
     * Scenario: Category no longer configured
     */
    #[Test]
    public function categoryMustBeConfigured(): void
    {
        $validator = new SubmissionValidator();

        self::assertSame('question', $validator->validate([...self::validFields(), 'category' => 'question'], null, ['bug', 'question'])->category, 'own value');
        self::assertSame(['category' => 'invalid'], $validator->validate([...self::validFields(), 'category' => 'idea'], null, ['bug', 'question'])->errors, 'removed value');
    }

    /**
     * Requirement: Input validation (10 to 5000 characters)
     */
    #[Test]
    public function messageWithExactlyTheLimitsIsValid(): void
    {
        $validator = new SubmissionValidator();

        self::assertTrue($validator->validate([...self::validFields(), 'message' => str_repeat('ä', 10)], null, self::CATEGORIES)->isValid());
        self::assertTrue($validator->validate([...self::validFields(), 'message' => str_repeat('ä', 5000)], null, self::CATEGORIES)->isValid());
    }

    /**
     * Scenario: Disguised file
     */
    #[Test]
    public function screenshotWithImageMediaTypeButOtherContentIsRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), $this->upload('<?php echo 1;', 'image/png'), self::CATEGORIES);

        self::assertSame(['screenshot' => 'invalidType'], $result->errors);
    }

    /**
     * Scenario: Too large
     */
    #[Test]
    public function screenshotAboveTenMegabytesIsRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), $this->upload(self::PNG . str_repeat('0', 10 * 1024 * 1024)), self::CATEGORIES);

        self::assertSame(['screenshot' => 'tooLarge'], $result->errors);
    }

    /**
     * Scenario: Too large
     */
    #[Test]
    public function screenshotExceedingPhpUploadLimitIsRejectedAsTooLarge(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), self::failedUpload(UPLOAD_ERR_INI_SIZE), self::CATEGORIES);

        self::assertSame(['screenshot' => 'tooLarge'], $result->errors);
    }

    /**
     * Requirement: Screenshot upload validation
     */
    #[Test]
    public function failedUploadIsRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), self::failedUpload(UPLOAD_ERR_PARTIAL), self::CATEGORIES);

        self::assertSame(['screenshot' => 'uploadFailed'], $result->errors);
    }

    /**
     * @return iterable<string, array{string, VideoType}>
     */
    public static function videoDataProvider(): iterable
    {
        yield 'MP4' => [self::MP4, VideoType::Mp4];
        yield 'WebM' => [self::WEBM, VideoType::Webm];
    }

    /**
     * Scenario: WebM video
     */
    #[Test]
    #[DataProvider('videoDataProvider')]
    public function videoTypeIsDetectedFromTheContent(string $content, VideoType $type): void
    {
        $result = new SubmissionValidator()->validate([...self::validFields(), 'contextAtEnd' => '{"module":"web_list"}'], null, self::CATEGORIES, $this->upload($content, 'application/octet-stream'), self::VIDEO_MAX_BYTES);

        self::assertTrue($result->isValid());
        self::assertSame($type, $result->video?->type);
        self::assertSame($content, $result->video->content);
        self::assertSame(['module' => 'web_list'], $result->contextAtEnd);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function invalidVideoDataProvider(): iterable
    {
        yield 'text as MP4' => ['just some text', self::VIDEO_MAX_BYTES, 'invalidType'];
        yield 'Matroska, not WebM' => [self::MATROSKA, self::VIDEO_MAX_BYTES, 'invalidType'];
        yield 'above the limit' => [self::MP4 . str_repeat('0', self::VIDEO_MAX_BYTES), self::VIDEO_MAX_BYTES, 'tooLarge'];
        yield 'video disabled' => [self::MP4, 0, 'videoDisabled'];
    }

    /**
     * Scenario: Disguised video
     * Scenario: Disabled
     */
    #[Test]
    #[DataProvider('invalidVideoDataProvider')]
    public function invalidVideoIsRejected(string $content, int $maxBytes, string $code): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), null, self::CATEGORIES, $this->upload($content, 'video/mp4'), $maxBytes);

        self::assertSame(['video' => $code], $result->errors);
    }

    /**
     * Scenario: Screenshot and video
     */
    #[Test]
    public function screenshotAndVideoTogetherAreRejected(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), $this->upload(self::PNG), self::CATEGORIES, $this->upload(self::WEBM), self::VIDEO_MAX_BYTES);

        self::assertSame(['video' => 'withScreenshot'], $result->errors);
    }

    /**
     * Requirement: Video upload validation (tooLarge)
     */
    #[Test]
    public function videoExceedingPhpUploadLimitIsRejectedAsTooLarge(): void
    {
        $result = new SubmissionValidator()->validate(self::validFields(), null, self::CATEGORIES, self::failedUpload(UPLOAD_ERR_INI_SIZE), self::VIDEO_MAX_BYTES);

        self::assertSame(['video' => 'tooLarge'], $result->errors);
    }

    /**
     * Requirement: Input validation (an error per field)
     */
    #[Test]
    public function allErrorsAreReportedAtOnce(): void
    {
        $result = new SubmissionValidator()->validate(['category' => 'x'], $this->upload('nope'), self::CATEGORIES);

        self::assertSame(
            ['message' => 'required', 'category' => 'invalid', 'severity' => 'required', 'source' => 'required', 'screenshot' => 'invalidType'],
            $result->errors,
        );
    }

    /**
     * Requirement: Pin validation (valid pins, unexpected keys)
     */
    #[Test]
    public function validPinsAreTrimmedSortedAndStrippedOfUnknownKeys(): void
    {
        $result = new SubmissionValidator()->validate([
            ...self::validFields(),
            'pins' => '[{"number":2,"comment":" typo "},{"number":1,"comment":"wrong logo","selector":".logo"}]',
        ], $this->upload(self::JPEG), self::CATEGORIES);

        self::assertTrue($result->isValid());
        self::assertSame([['number' => 1, 'comment' => 'wrong logo'], ['number' => 2, 'comment' => 'typo']], $result->pins);
    }

    /**
     * Requirement: Pin validation (an empty or missing field means no pins)
     */
    #[Test]
    #[DataProvider('noPinsProvider')]
    public function emptyPinsMeanNoPins(string $pins): void
    {
        $result = new SubmissionValidator()->validate([...self::validFields(), 'pins' => $pins], null, self::CATEGORIES);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->pins);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noPinsProvider(): array
    {
        return ['missing' => [''], 'empty list' => ['[]']];
    }

    /**
     * Requirement: Pin validation
     */
    #[Test]
    #[DataProvider('invalidPinsProvider')]
    public function invalidPinsAreRejected(string $pins): void
    {
        $result = new SubmissionValidator()->validate([...self::validFields(), 'pins' => $pins], $this->upload(self::JPEG), self::CATEGORIES);

        self::assertSame(['pins' => 'invalid'], $result->errors);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPinsProvider(): array
    {
        $pin = static fn(int $number, string $comment = 'typo'): array => ['number' => $number, 'comment' => $comment];

        return [
            'duplicate number' => [(string)json_encode([$pin(1), $pin(1)])],
            'comment too long' => [(string)json_encode([$pin(1, str_repeat('ä', 501))])],
            'empty comment' => [(string)json_encode([$pin(1, '   ')])],
            'number zero' => [(string)json_encode([$pin(0)])],
            'number above 20' => [(string)json_encode([$pin(21)])],
            'number as string' => ['[{"number":"1","comment":"typo"}]'],
            'more than 20 pins' => [(string)json_encode(array_map($pin, range(1, 21)))],
            'object instead of list' => ['{"number":1,"comment":"typo"}'],
            'no JSON' => ['[{'],
        ];
    }

    #[Test]
    public function commentWithExactly500CharactersIsValid(): void
    {
        $result = new SubmissionValidator()->validate([
            ...self::validFields(),
            'pins' => (string)json_encode([['number' => 1, 'comment' => str_repeat('ä', 500)]]),
        ], $this->upload(self::JPEG), self::CATEGORIES);

        self::assertTrue($result->isValid());
    }

    /**
     * Requirement: Pin validation (pins without screenshot)
     */
    #[Test]
    public function pinsWithoutScreenshotAreRejected(): void
    {
        $result = new SubmissionValidator()->validate([...self::validFields(), 'pins' => '[{"number":1,"comment":"typo"}]'], null, self::CATEGORIES);

        self::assertSame(['pins' => 'invalid'], $result->errors);
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
