<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Configuration;

/**
 * Parses a configured list like "bug,question=Question" into value => configured label
 * ('' without label), in the configured order. Numeric values like "1" become int keys (PHP arrays).
 */
final class ConfiguredValueList
{
    private const string VALUE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,29}$/';

    /**
     * Invalid entries and repeated values are ignored, the first occurrence wins.
     *
     * @param array<array-key, string> $default used when no valid entry is left
     * @return array<array-key, string>
     */
    public static function parse(string $setting, array $default): array
    {
        $list = [];
        foreach (explode(',', $setting) as $entry) {
            [$value, $label] = array_map(trim(...), array_pad(explode('=', $entry, 2), 2, ''));
            if ($value !== '' && preg_match(self::VALUE_PATTERN, $value) === 1 && !isset($list[$value])) {
                $list[$value] = $label;
            }
        }

        return $list !== [] ? $list : $default;
    }
}
