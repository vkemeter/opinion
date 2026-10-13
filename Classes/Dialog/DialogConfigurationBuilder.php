<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dialog;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Collection\CollectionFilter;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Validation\SubmissionValidator;

/**
 * Builds the configuration the shared JavaScript dialog is opened with:
 * endpoint (including the request token), translated labels and options.
 */
final readonly class DialogConfigurationBuilder
{
    private const array LABEL_KEYS = [
        'title' => 'dialog.title',
        'intro' => 'dialog.intro',
        'message' => 'dialog.message',
        'messageHint' => 'dialog.messageHint',
        'charactersLeft' => 'dialog.charactersLeft',
        'category' => 'dialog.category',
        'severity' => 'dialog.severity',
        'send' => 'dialog.send',
        'sending' => 'dialog.sending',
        'cancel' => 'dialog.cancel',
        'close' => 'dialog.close',
        'error' => 'dialog.error',
        'sessionExpired' => 'dialog.sessionExpired',
        'screenshot' => 'dialog.screenshot',
        'screenshotHint' => 'dialog.screenshotHint',
        'screenshotTake' => 'dialog.screenshotTake',
        'screenshotRetake' => 'dialog.screenshotRetake',
        'screenshotRemove' => 'dialog.screenshotRemove',
        'screenshotPreview' => 'dialog.screenshotPreview',
        'screenshotUnsupported' => 'dialog.screenshotUnsupported',
        'screenshotDenied' => 'dialog.screenshotDenied',
        'screenshotFailed' => 'dialog.screenshotFailed',
    ];

    public function __construct(
        private AccessChecker $accessChecker,
        private UriBuilder $uriBuilder,
        private LanguageServiceFactory $languageServiceFactory,
        private ConfigurationProviderInterface $configurationProvider,
        private CollectionFilter $collectionFilter,
    ) {}

    /**
     * @param array<string, mixed> $context entry point context, validated and filtered again on submit
     * @return array<string, mixed>
     */
    public function build(BackendUserAuthentication $backendUser, Source $source, array $context = []): array
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($backendUser);
        $translate = static fn(string $key): string => $languageService->sL('opinion.messages:' . $key);
        $configuration = $this->configurationProvider->getConfiguration();

        return [
            'endpoint' => (string)$this->uriBuilder->buildUriFromRoute('ajax_opinion_submit'),
            'source' => $source->value,
            'context' => $this->collectionFilter->filter('context', $context, $configuration),
            // The browser collects only these, the server filters again on submit
            'collect' => [
                'client' => $this->collectionFilter->getEnabledNames('client', $configuration),
                'context' => $this->collectionFilter->getEnabledNames('context', $configuration),
            ],
            'messageMinLength' => SubmissionValidator::MESSAGE_MIN_LENGTH,
            'messageMaxLength' => SubmissionValidator::MESSAGE_MAX_LENGTH,
            'categories' => array_map(
                static fn(Category $category): array => ['value' => $category->value, 'label' => $translate('category.' . $category->value)],
                Category::cases(),
            ),
            'severities' => array_map(
                static fn(Severity $severity): array => ['value' => $severity->value, 'label' => $translate('severity.' . $severity->value)],
                Severity::cases(),
            ),
            'defaultSeverity' => Severity::Normal->value,
            'labels' => array_map($translate, self::LABEL_KEYS),
            'unavailable' => $this->accessChecker->hasValidEmail($backendUser) ? null : $this->buildUnavailableNotice($translate),
        ];
    }

    /**
     * @param \Closure(string): string $translate
     * @return array{message: string, settingsUrl: string, settingsLabel: string}
     */
    private function buildUnavailableNotice(\Closure $translate): array
    {
        return [
            'message' => $translate('dialog.emailMissing'),
            // Opens the backend with the user settings, also from the frontend
            'settingsUrl' => (string)$this->uriBuilder->buildUriFromRoute('user_setup', [], UriBuilder::SHAREABLE_URL),
            'settingsLabel' => $translate('dialog.emailMissingLink'),
        ];
    }
}
