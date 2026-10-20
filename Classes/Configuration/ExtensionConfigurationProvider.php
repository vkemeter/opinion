<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Configuration;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vkemeter\Opinion\Collection\CollectableField;

#[AsAlias(ConfigurationProviderInterface::class)]
final readonly class ExtensionConfigurationProvider implements ConfigurationProviderInterface
{
    private const string DEFAULT_SUBJECT_PREFIX = '[Opinion]';
    private const int DEFAULT_RETENTION_DAYS = 180;
    private const int DEFAULT_REDELIVER_MAX_ATTEMPTS = 5;
    private const int DEFAULT_RATE_LIMIT = 10;
    public const string DEFAULT_DENIED_URL_PARAMETERS = 'ADMCMD_*,logintype,user,pass,redirect_url,tx_felogin_login';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getConfiguration(): OpinionConfiguration
    {
        try {
            $settings = $this->extensionConfiguration->get('opinion');
        } catch (ExtensionConfigurationExtensionNotConfiguredException) {
            $settings = [];
        }
        $settings = is_array($settings) ? $settings : [];

        $retentionDays = (int)($settings['retentionDays'] ?? self::DEFAULT_RETENTION_DAYS);

        return new OpinionConfiguration(
            enabled: (bool)($settings['enabled'] ?? true),
            recipients: $this->parseRecipients((string)($settings['recipients'] ?? '')),
            subjectPrefix: trim((string)($settings['subjectPrefix'] ?? self::DEFAULT_SUBJECT_PREFIX)),
            retentionDays: $retentionDays > 0 ? $retentionDays : self::DEFAULT_RETENTION_DAYS,
            disabledFields: array_values(array_filter(
                CollectableField::cases(),
                static fn(CollectableField $field): bool => !(bool)($settings[$field->getConfigurationKey()] ?? true),
            )),
            deniedUrlParameters: GeneralUtility::trimExplode(',', (string)($settings['deniedUrlParameters'] ?? self::DEFAULT_DENIED_URL_PARAMETERS), true),
            redeliverMaxAttempts: max(0, (int)($settings['redeliverMaxAttempts'] ?? self::DEFAULT_REDELIVER_MAX_ATTEMPTS)),
            rateLimit: max(0, (int)($settings['rateLimit'] ?? self::DEFAULT_RATE_LIMIT)),
        );
    }

    /**
     * @return list<string>
     */
    private function parseRecipients(string $recipients): array
    {
        return array_values(array_unique(array_filter(
            GeneralUtility::trimExplode(',', $recipients, true),
            GeneralUtility::validEmail(...),
        )));
    }
}
