<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dialog;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Collection\CollectionFilter;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Formatting\ConfiguredLabels;
use Vkemeter\Opinion\Validation\SubmissionValidator;

/**
 * Builds the configuration the shared JavaScript dialog is opened with:
 * endpoint (including the request token), translated labels and options.
 */
final readonly class DialogConfigurationBuilder
{
    /** Room in post_max_size for the form fields next to the video */
    private const int POST_FIELDS_MARGIN = 256 * 1024;
    private const int VIDEO_MIN_DURATION = 10;

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
        'timeout' => 'dialog.timeout',
        'screenshot' => 'dialog.screenshot',
        'screenshotHint' => 'dialog.screenshotHint',
        'screenshotTake' => 'dialog.screenshotTake',
        'screenshotRetake' => 'dialog.screenshotRetake',
        'screenshotRemove' => 'dialog.screenshotRemove',
        'screenshotPreview' => 'dialog.screenshotPreview',
        'screenshotUnsupported' => 'dialog.screenshotUnsupported',
        'screenshotDenied' => 'dialog.screenshotDenied',
        'screenshotFailed' => 'dialog.screenshotFailed',
        'capture' => 'dialog.capture',
        'captureScreenshot' => 'dialog.captureScreenshot',
        'captureVideo' => 'dialog.captureVideo',
        'videoNotice' => 'dialog.videoNotice',
        'videoStart' => 'dialog.videoStart',
        'videoRetake' => 'dialog.videoRetake',
        'videoRemove' => 'dialog.videoRemove',
        'videoPreview' => 'dialog.videoPreview',
        'videoRecording' => 'dialog.videoRecording',
        'videoTimeLeft' => 'dialog.videoTimeLeft',
        'videoStop' => 'dialog.videoStop',
        'videoDenied' => 'dialog.videoDenied',
        'videoFailed' => 'dialog.videoFailed',
        'annotate' => 'dialog.annotate',
        'annotationTitle' => 'dialog.annotationTitle',
        'annotationHint' => 'dialog.annotationHint',
        'annotationTools' => 'dialog.annotationTools',
        'annotationRectangle' => 'dialog.annotationRectangle',
        'annotationArrow' => 'dialog.annotationArrow',
        'annotationText' => 'dialog.annotationText',
        'annotationTextField' => 'dialog.annotationTextField',
        'annotationBlackout' => 'dialog.annotationBlackout',
        'annotationCrop' => 'dialog.annotationCrop',
        'annotationResetCrop' => 'dialog.annotationResetCrop',
        'annotationColor' => 'dialog.annotationColor',
        'annotationColorRed' => 'dialog.annotationColorRed',
        'annotationColorYellow' => 'dialog.annotationColorYellow',
        'annotationColorBlue' => 'dialog.annotationColorBlue',
        'annotationUndo' => 'dialog.annotationUndo',
        'annotationClear' => 'dialog.annotationClear',
        'annotationApply' => 'dialog.annotationApply',
        'annotationCanvas' => 'dialog.annotationCanvas',
        'annotationFailed' => 'dialog.annotationFailed',
    ];

    public function __construct(
        private AccessChecker $accessChecker,
        private UriBuilder $uriBuilder,
        private LanguageServiceFactory $languageServiceFactory,
        private ConfigurationProviderInterface $configurationProvider,
        private CollectionFilter $collectionFilter,
        private ConfiguredLabels $configuredLabels,
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
        $video = self::videoLimits($configuration, self::phpUploadLimit());

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
                fn(string $category): array => ['value' => $category, 'label' => $this->configuredLabels->label('category', $category, $configuration, $languageService)],
                $configuration->categoryValues(),
            ),
            'severities' => array_map(
                static fn(Severity $severity): array => ['value' => $severity->value, 'label' => $translate('severity.' . $severity->value)],
                Severity::cases(),
            ),
            'defaultSeverity' => Severity::Normal->value,
            'captureKinds' => $video === null ? ['screenshot'] : ['screenshot', 'video'],
            'video' => $video,
            'labels' => array_map($translate, self::LABEL_KEYS),
            'unavailable' => $this->accessChecker->hasValidEmail($backendUser) ? null : $this->buildUnavailableNotice($translate),
        ];
    }

    /**
     * The video limits for the dialog, null when video is disabled or the upload limit allows less than 10 seconds.
     *
     * @param int $phpUploadLimit bytes PHP accepts for one upload
     * @return array{uploadLimit: int, maxDuration: int}|null maxDuration: seconds the recorder may run within the upload limit
     */
    public static function videoLimits(OpinionConfiguration $configuration, int $phpUploadLimit): ?array
    {
        $uploadLimit = min($configuration->videoMaxSize(), $phpUploadLimit);
        $maxDuration = min($configuration->videoMaxDuration, intdiv($uploadLimit, OpinionConfiguration::VIDEO_BYTES_PER_SECOND));
        if (!$configuration->videoEnabled || $maxDuration < self::VIDEO_MIN_DURATION) {
            return null;
        }

        return ['uploadLimit' => $uploadLimit, 'maxDuration' => $maxDuration];
    }

    /**
     * The smaller of upload_max_filesize and post_max_size without room for the other fields; 0 in PHP means no limit.
     */
    private static function phpUploadLimit(): int
    {
        $uploadMaxFilesize = GeneralUtility::getBytesFromSizeMeasurement((string)ini_get('upload_max_filesize'));
        $postMaxSize = GeneralUtility::getBytesFromSizeMeasurement((string)ini_get('post_max_size'));
        $limits = [];
        if ($uploadMaxFilesize > 0) {
            $limits[] = $uploadMaxFilesize;
        }
        if ($postMaxSize > 0) {
            $limits[] = $postMaxSize - self::POST_FIELDS_MARGIN;
        }

        return $limits === [] ? PHP_INT_MAX : max(0, min($limits));
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
