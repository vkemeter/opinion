<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Formatting;

/**
 * Turns nested data into label/value rows for display, e.g. "screen.width" => "2560".
 */
final readonly class DataFlattener
{
    /**
     * @param array<array-key, mixed> $data
     * @return array<string, string>
     */
    public function flatten(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $label = $prefix . $key;
            if (is_array($value)) {
                $rows = [...$rows, ...$this->flatten($value, $label . '.')];
                continue;
            }
            $rows[$label] = match (true) {
                is_bool($value) => $value ? 'yes' : 'no',
                is_scalar($value) => (string)$value,
                default => '',
            };
        }

        return $rows;
    }
}
