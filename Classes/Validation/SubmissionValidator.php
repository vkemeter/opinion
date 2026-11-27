<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Validation;

use Psr\Http\Message\UploadedFileInterface;
use Vkemeter\Opinion\Domain\Screenshot;
use Vkemeter\Opinion\Domain\ScreenshotType;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Video;
use Vkemeter\Opinion\Domain\VideoType;

/**
 * Validates the raw form fields and the screenshot or video upload of a submission.
 * The file type is detected from the content, never from the client.
 */
final readonly class SubmissionValidator
{
    public const int MESSAGE_MIN_LENGTH = 10;
    public const int MESSAGE_MAX_LENGTH = 5000;
    public const int JSON_MAX_BYTES = 65536;
    public const int SCREENSHOT_MAX_BYTES = 10 * 1024 * 1024;
    public const int PINS_MAX = 20;
    public const int PIN_COMMENT_MAX_LENGTH = 500;

    /**
     * @param array<array-key, mixed> $fields
     * @param list<string> $categories the configured category values
     * @param int $videoMaxBytes largest accepted video, 0 = video disabled
     */
    public function validate(array $fields, ?UploadedFileInterface $screenshotUpload, array $categories, ?UploadedFileInterface $videoUpload = null, int $videoMaxBytes = 0): ValidationResult
    {
        $errors = [];

        $message = is_string($fields['message'] ?? null) ? trim($fields['message']) : '';
        $messageLength = mb_strlen($message);
        if ($message === '') {
            $errors['message'] = 'required';
        } elseif ($messageLength < self::MESSAGE_MIN_LENGTH) {
            $errors['message'] = 'tooShort';
        } elseif ($messageLength > self::MESSAGE_MAX_LENGTH) {
            $errors['message'] = 'tooLong';
        }

        $category = is_string($fields['category'] ?? null) ? $fields['category'] : '';
        if ($category === '') {
            $errors['category'] = 'required';
        } elseif (!in_array($category, $categories, true)) {
            $errors['category'] = 'invalid';
        }
        $severity = $this->validateEnum($fields, 'severity', Severity::class, $errors);
        $source = $this->validateEnum($fields, 'source', Source::class, $errors);
        $clientInfo = $this->validateJsonObject($fields, 'clientInfo', $errors);
        $context = $this->validateJsonObject($fields, 'context', $errors);
        $contextAtEnd = $this->validateJsonObject($fields, 'contextAtEnd', $errors);
        $screenshot = $this->validateScreenshot($screenshotUpload, $errors);
        $video = $this->validateVideo($videoUpload, $videoMaxBytes, $errors);
        if ($screenshot !== null && $video !== null) {
            $errors['video'] = 'withScreenshot';
        }
        $pins = $this->validatePins($fields, $screenshot !== null || isset($errors['screenshot']), $errors);

        if ($errors !== []) {
            return new ValidationResult($errors);
        }

        return new ValidationResult([], $message, $category, $severity, $source, $clientInfo, $context, $screenshot, $video, $contextAtEnd, $pins);
    }

    /**
     * @template T of \BackedEnum
     * @param array<array-key, mixed> $fields
     * @param class-string<T> $enumClass
     * @param array<string, string> $errors
     * @return T|null
     */
    private function validateEnum(array $fields, string $field, string $enumClass, array &$errors): ?\BackedEnum
    {
        $value = $fields[$field] ?? null;
        if (!is_string($value) || $value === '') {
            $errors[$field] = 'required';
            return null;
        }

        $case = $enumClass::tryFrom($value);
        if ($case === null) {
            $errors[$field] = 'invalid';
        }

        return $case;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function validateJsonObject(array $fields, string $field, array &$errors): array
    {
        $value = $fields[$field] ?? '';
        if ($value === '') {
            return [];
        }
        if (!is_string($value)) {
            $errors[$field] = 'invalid';
            return [];
        }
        if (strlen($value) > self::JSON_MAX_BYTES) {
            $errors[$field] = 'tooLarge';
            return [];
        }

        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $errors[$field] = 'invalid';
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Pins as list of number and comment, sorted by number, or null when the value is not valid.
     *
     * @return list<array{number: int, comment: string}>|null
     */
    public static function normalizePins(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::PINS_MAX) {
            return null;
        }
        $pins = [];
        foreach ($value as $pin) {
            $number = is_array($pin) ? ($pin['number'] ?? null) : null;
            $comment = is_array($pin) && is_string($pin['comment'] ?? null) ? trim($pin['comment']) : '';
            if (!is_int($number) || $number < 1 || $number > self::PINS_MAX || isset($pins[$number])
                || $comment === '' || mb_strlen($comment) > self::PIN_COMMENT_MAX_LENGTH) {
                return null;
            }
            $pins[$number] = ['number' => $number, 'comment' => $comment];
        }
        ksort($pins);

        return array_values($pins);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @param bool $hasScreenshot whether a screenshot was uploaded, also an invalid one
     * @param array<string, string> $errors
     * @return list<array{number: int, comment: string}>
     */
    private function validatePins(array $fields, bool $hasScreenshot, array &$errors): array
    {
        $value = $fields['pins'] ?? '';
        if ($value === '') {
            return [];
        }
        if (!is_string($value) || strlen($value) > self::JSON_MAX_BYTES) {
            $errors['pins'] = 'invalid';
            return [];
        }
        try {
            $pins = self::normalizePins(json_decode($value, true, 16, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            $pins = null;
        }
        if ($pins === null || ($pins !== [] && !$hasScreenshot)) {
            $errors['pins'] = 'invalid';
            return [];
        }

        return $pins;
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateScreenshot(?UploadedFileInterface $upload, array &$errors): ?Screenshot
    {
        $content = $this->readUpload($upload, 'screenshot', self::SCREENSHOT_MAX_BYTES, $errors);
        if ($content === null) {
            return null;
        }

        $type = ScreenshotType::fromContent($content);
        if ($type === null) {
            $errors['screenshot'] = 'invalidType';
            return null;
        }

        return new Screenshot($content, $type);
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateVideo(?UploadedFileInterface $upload, int $maxBytes, array &$errors): ?Video
    {
        if ($maxBytes <= 0 && $upload !== null && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
            $errors['video'] = 'videoDisabled';
            return null;
        }
        $content = $this->readUpload($upload, 'video', $maxBytes, $errors);
        if ($content === null) {
            return null;
        }

        $type = VideoType::fromContent($content);
        if ($type === null) {
            $errors['video'] = 'invalidType';
            return null;
        }

        return new Video($content, $type);
    }

    /**
     * @param array<string, string> $errors
     * @return string|null the content, null without a file or on an error
     */
    private function readUpload(?UploadedFileInterface $upload, string $field, int $maxBytes, array &$errors): ?string
    {
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $errors[$field] = 'tooLarge';
            return null;
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            $errors[$field] = 'uploadFailed';
            return null;
        }
        if (($upload->getSize() ?? 0) > $maxBytes) {
            $errors[$field] = 'tooLarge';
            return null;
        }

        $content = (string)$upload->getStream();
        if (strlen($content) > $maxBytes) {
            $errors[$field] = 'tooLarge';
            return null;
        }

        return $content;
    }
}
