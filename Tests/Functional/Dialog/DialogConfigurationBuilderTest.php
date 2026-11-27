<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Dialog;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Dialog\DialogConfigurationBuilder;
use Vkemeter\Opinion\Domain\Source;

final class DialogConfigurationBuilderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    /**
     * Requirement: Dialog form (labels from the server configuration)
     */
    #[Test]
    public function buildContainsTokenisedEndpointOptionsAndLabels(): void
    {
        $backendUser = $this->setUpBackendUser(1);

        $configuration = $this->get(DialogConfigurationBuilder::class)->build($backendUser, Source::Frontend, ['pageUid' => 12]);

        self::assertMatchesRegularExpression('#/typo3/ajax/opinion/submit\?token=[0-9a-f]{40}#', $configuration['endpoint']);
        self::assertSame('frontend', $configuration['source']);
        self::assertSame(['pageUid' => 12], $configuration['context']);
        self::assertSame(10, $configuration['messageMinLength']);
        self::assertSame(5000, $configuration['messageMaxLength']);
        self::assertSame(['bug', 'content', 'idea', 'other'], array_column($configuration['categories'], 'value'));
        self::assertSame('Bug', $configuration['categories'][0]['label']);
        self::assertSame(['low', 'normal', 'high'], array_column($configuration['severities'], 'value'));
        self::assertSame('normal', $configuration['defaultSeverity']);
        self::assertSame('Send feedback', $configuration['labels']['title']);
        self::assertSame('Characters left: {count}', $configuration['labels']['charactersLeft']);
        self::assertNotContains('', $configuration['labels']);
        self::assertContains('screen', $configuration['collect']['client']);
        self::assertContains('module', $configuration['collect']['context']);
    }

    /**
     * Scenario: Video disabled
     * Requirement: Upload limit known in advance
     */
    #[Test]
    public function videoIsOfferedOnlyWhenEnabled(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $builder = $this->get(DialogConfigurationBuilder::class);

        $configuration = $builder->build($backendUser, Source::Backend);
        self::assertSame(['screenshot'], $configuration['captureKinds']);
        self::assertNull($configuration['video']);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['videoEnabled'] = '1';
        $configuration = $builder->build($backendUser, Source::Backend);
        self::assertSame(['screenshot', 'video'], $configuration['captureKinds']);
        $phpLimits = array_filter(array_map(
            static fn(string $setting): int => GeneralUtility::getBytesFromSizeMeasurement((string)ini_get($setting)),
            ['upload_max_filesize', 'post_max_size'],
        ), static fn(int $bytes): bool => $bytes > 0);
        self::assertSame(min([60 * 160 * 1024, ...$phpLimits]), $configuration['video']['uploadLimit'], 'smallest of server video limit, upload_max_filesize and post_max_size');
        self::assertGreaterThanOrEqual(10, $configuration['video']['maxDuration']);
        self::assertLessThanOrEqual(60, $configuration['video']['maxDuration']);
    }

    /**
     * Scenario: Configured categories
     * Scenario: Own category without label
     */
    #[Test]
    public function categoriesFollowTheConfigurationWithTheirLabels(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['categories'] = 'question=Question,bug,design';

        $configuration = $this->get(DialogConfigurationBuilder::class)->build($this->setUpBackendUser(1), Source::Backend);

        self::assertSame(
            [['value' => 'question', 'label' => 'Question'], ['value' => 'bug', 'label' => 'Bug'], ['value' => 'design', 'label' => 'design']],
            $configuration['categories'],
            'configured order, the first one is preselected by the dialog',
        );
    }

    /**
     * Requirement: Valid category values (digits allowed)
     */
    #[Test]
    public function numericCategoryValuesStayStrings(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['categories'] = '1=First,2';

        $configuration = $this->get(DialogConfigurationBuilder::class)->build($this->setUpBackendUser(1), Source::Backend);

        self::assertSame([['value' => '1', 'label' => 'First'], ['value' => '2', 'label' => '2']], $configuration['categories']);
    }

    /**
     * Scenario: Disabled detail
     */
    #[Test]
    public function switchedOffDetailsAreNeitherCollectedNorEmbedded(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion'] = ['collect_client_screen' => '0', 'collect_context_imagesOnPage' => '0', 'collect_context_page' => '0'];
        $backendUser = $this->setUpBackendUser(1);

        $configuration = $this->get(DialogConfigurationBuilder::class)->build($backendUser, Source::Frontend, [
            'pageUid' => 12,
            'siteIdentifier' => 'default',
            'pageInfo' => ['imagesOnPage' => ['total' => 3], 'parseTimeMs' => 10],
        ]);

        self::assertNotContains('screen', $configuration['collect']['client']);
        self::assertContains('viewport', $configuration['collect']['client']);
        self::assertNotContains('page', $configuration['collect']['context']);
        self::assertSame(['siteIdentifier' => 'default', 'pageInfo' => ['parseTimeMs' => 10]], $configuration['context']);
    }

    /**
     * Scenario: Missing email address
     */
    #[Test]
    public function userWithEmailAddressGetsTheForm(): void
    {
        self::assertNull($this->get(DialogConfigurationBuilder::class)->build($this->setUpBackendUser(1), Source::Backend)['unavailable']);
    }

    /**
     * Scenario: Missing email address
     */
    #[Test]
    public function userWithoutEmailAddressGetsANoticeWithLinkToTheUserSettings(): void
    {
        $unavailable = $this->get(DialogConfigurationBuilder::class)->build($this->setUpBackendUser(4), Source::Backend)['unavailable'];

        self::assertIsArray($unavailable);
        self::assertStringContainsString('no email address', $unavailable['message']);
        self::assertStringEndsWith('/typo3/module/user/setup', $unavailable['settingsUrl']);
        self::assertSame('Open user settings', $unavailable['settingsLabel']);
    }

    /**
     * Scenario: Shipped category
     */
    #[Test]
    public function labelsFollowTheBackendUserLanguage(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->user['lang'] = 'de';

        $configuration = $this->get(DialogConfigurationBuilder::class)->build($backendUser, Source::Backend);

        self::assertSame('Feedback senden', $configuration['labels']['title']);
        self::assertSame('Verbleibende Zeichen: {count}', $configuration['labels']['charactersLeft']);
        self::assertSame('Schwärzen', $configuration['labels']['annotationBlackout']);
        self::assertSame('Zuschneiden', $configuration['labels']['annotationCrop']);
        self::assertSame('Zuschnitt aufheben', $configuration['labels']['annotationResetCrop']);
        self::assertSame('Pin mit Kommentar', $configuration['labels']['annotationPin']);
        self::assertSame('Kommentar zu Pin {number}', $configuration['labels']['annotationPinField']);
        self::assertSame('Pin-Kommentare', $configuration['labels']['pinComments']);
        self::assertSame('Hinzufügen', $configuration['labels']['annotationPinAdd']);
        self::assertArrayNotHasKey('annotationText', $configuration['labels']);
        self::assertSame('Fehler', $configuration['categories'][0]['label']);
    }
}
