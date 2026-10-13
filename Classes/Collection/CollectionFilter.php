<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Collection;

use Vkemeter\Opinion\Configuration\OpinionConfiguration;

/**
 * Removes the details the admin switched off from client info, system info
 * and context. Runs on the server, whatever the browser sent.
 */
final readonly class CollectionFilter
{
    /**
     * @param 'client'|'system'|'context' $section
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filter(string $section, array $data, OpinionConfiguration $configuration): array
    {
        foreach (CollectableField::cases() as $field) {
            if ($field->getSection() !== $section || $configuration->collects($field)) {
                continue;
            }
            foreach ($field->getPaths() as $path) {
                $data = $this->remove($data, $path);
            }
        }

        return $data;
    }

    /**
     * Names of the enabled client fields, the browser collects only these.
     *
     * @return list<string>
     */
    public function getEnabledNames(string $section, OpinionConfiguration $configuration): array
    {
        $names = [];
        foreach (CollectableField::cases() as $field) {
            if ($field->getSection() === $section && $configuration->collects($field)) {
                $names[] = explode('.', $field->value, 2)[1];
            }
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $path
     * @return array<array-key, mixed>
     */
    private function remove(array $data, array $path): array
    {
        $key = array_shift($path);
        if ($key === null || !array_key_exists($key, $data)) {
            return $data;
        }
        if ($path === []) {
            unset($data[$key]);
            return $data;
        }
        if (is_array($data[$key])) {
            $data[$key] = $this->remove($data[$key], $path);
            if ($data[$key] === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
