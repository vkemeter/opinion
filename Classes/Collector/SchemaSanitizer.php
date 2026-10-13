<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Collector;

/**
 * Reduces untrusted data to the keys of a schema, with known types and limits.
 *
 * Scalar types: string:<max length>, int, float, bool, enum:<a|b|c>, url.
 * Nested arrays are objects, a one-element list describes a list of that shape.
 * Keys not in the schema and values of the wrong type are dropped.
 */
final readonly class SchemaSanitizer
{
    private const int MAX_INT = 100000000;

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function sanitize(array $data, array $schema, int $maxListItems = 10): array
    {
        return $this->sanitizeObject($data, $schema, $maxListItems);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function sanitizeObject(array $data, array $schema, int $maxListItems): array
    {
        $result = [];
        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->sanitizeValue($data[$key], $type, $maxListItems);
            if ($value !== null && $value !== []) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function sanitizeValue(mixed $value, mixed $type, int $maxListItems): mixed
    {
        if (is_array($type)) {
            if (!is_array($value)) {
                return null;
            }
            if (array_is_list($type)) {
                return $this->sanitizeList($value, $type[0], $maxListItems);
            }

            /** @var array<string, mixed> $type */
            return $this->sanitizeObject($value, $type, $maxListItems);
        }

        return $this->sanitizeScalar($value, (string)$type);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<mixed>
     */
    private function sanitizeList(array $values, mixed $type, int $maxListItems): array
    {
        if (!array_is_list($values)) {
            return [];
        }

        $result = [];
        foreach (array_slice($values, 0, $maxListItems) as $value) {
            $sanitized = $this->sanitizeValue($value, $type, $maxListItems);
            if ($sanitized !== null && $sanitized !== []) {
                $result[] = $sanitized;
            }
        }

        return $result;
    }

    private function sanitizeScalar(mixed $value, string $type): string|int|float|bool|null
    {
        [$kind, $option] = array_pad(explode(':', $type, 2), 2, '');

        return match ($kind) {
            'string' => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, (int)$option) : null,
            'int' => is_int($value) || (is_float($value) && floor($value) === $value) ? max(0, min((int)$value, self::MAX_INT)) : null,
            'float' => is_int($value) || is_float($value) ? round(max(0.0, min((float)$value, 100.0)), 3) : null,
            'bool' => is_bool($value) ? $value : null,
            'enum' => is_string($value) && in_array($value, explode('|', $option), true) ? $value : null,
            'url' => is_string($value) && strlen($value) <= 2048 && preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null,
            default => null,
        };
    }
}
