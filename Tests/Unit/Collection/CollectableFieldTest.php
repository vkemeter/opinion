<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Collection;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Collection\CollectableField;

final class CollectableFieldTest extends UnitTestCase
{
    #[Test]
    public function everyFieldHasASwitchInTheExtensionConfiguration(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../ext_conf_template.txt');

        foreach (CollectableField::cases() as $field) {
            self::assertMatchesRegularExpression('/^' . preg_quote($field->getConfigurationKey(), '/') . ' = 1$/m', $template, $field->value);
            self::assertStringContainsString('label=opinion.messages:extconf.' . $field->getConfigurationKey(), $template);
        }
    }

    #[Test]
    public function configurationKeysAreUnique(): void
    {
        $keys = array_map(static fn(CollectableField $field): string => $field->getConfigurationKey(), CollectableField::cases());

        self::assertSame($keys, array_unique($keys));
        self::assertSame('collect_system_ipAddress', CollectableField::SystemIpAddress->getConfigurationKey());
    }
}
