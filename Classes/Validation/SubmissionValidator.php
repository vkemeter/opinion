<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Validation;

use Psr\Http\Message\UploadedFileInterface;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Screenshot;
use Vkemeter\Opinion\Domain\ScreenshotType;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;

/**
 * Validates the raw form fields and the screenshot upload of a submission.
 * The screenshot type is detected from the content, never from the client.
 */
final readonly class SubmissionValidator
{
    public const int MESSAGE_MIN_LENGTH = 10;
    public const int MESSAGE_MAX_LENGTH = 5000;
    public const int JSON_MAX_BYTES = 65536;
    public const int SCREENSHOT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @param array<array-key, mixed> $fields
     */
    public function validate(array $fields, ?UploadedFileInterface $screenshotUpload): ValidationResult
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

        $category = $this->validateEnum($fields, 'category', Category::class, $errors);
        $severity = $this->validateEnum($fields, 'severity', Severity::class, $errors);
        $source = $this->validateEnum($fields, 'source', Source::class, $errors);
        $clientInfo = $this->validateJsonObject($fields, 'clientInfo', $errors);
        $context = $this->validateJsonObject($fields, 'context', $errors);
        $screenshot = $this->validateScreenshot($screenshotUpload, $errors);

        if ($errors !== []) {
            return new ValidationResult($errors);
        }

        return new ValidationResult([], $message, $category, $severity, $source, $clientInfo, $context, $screenshot);
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
     * @param array<string, string> $errors
     */
    private function validateScreenshot(?UploadedFileInterface $upload, array &$errors): ?Screenshot
    {
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $errors['screenshot'] = 'tooLarge';
            return null;
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            $errors['screenshot'] = 'uploadFailed';
            return null;
        }
        if (($upload->getSize() ?? 0) > self::SCREENSHOT_MAX_BYTES) {
            $errors['screenshot'] = 'tooLarge';
            return null;
        }

        $content = (string)$upload->getStream();
        if (strlen($content) > self::SCREENSHOT_MAX_BYTES) {
            $errors['screenshot'] = 'tooLarge';
            return null;
        }

        $type = ScreenshotType::fromContent($content);
        if ($type === null) {
            $errors['screenshot'] = 'invalidType';
            return null;
        }

        return new Screenshot($content, $type);
    }
}
