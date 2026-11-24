<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Formatting;

use TYPO3\CMS\Core\Localization\LanguageService;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;

/**
 * Label of a value of a configured list (categories): the translation
 * opinion.messages:<list>.<value>, otherwise the configured label, otherwise the value.
 */
final readonly class ConfiguredLabels
{
    public function label(string $list, string $value, OpinionConfiguration $configuration, LanguageService $languageService): string
    {
        $configured = match ($list) {
            'category' => $configuration->categories,
            default => throw new \InvalidArgumentException(sprintf('Unknown configured list "%s".', $list), 1795551289),
        };
        $translation = $languageService->sL('LLL:opinion.messages:' . $list . '.' . $value);
        if ($translation !== '') {
            return $translation;
        }

        return ($configured[$value] ?? '') !== '' ? $configured[$value] : $value;
    }
}
