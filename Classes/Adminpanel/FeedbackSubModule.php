<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Adminpanel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Adminpanel\ModuleApi\ContentProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\DataProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleData;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleInterface;
use TYPO3\CMS\Adminpanel\Modules\Info\GeneralInformation;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use Vkemeter\Opinion\Dialog\DialogConfigurationBuilder;
use Vkemeter\Opinion\Domain\Source;

/**
 * Content of the "Feedback" tab: a button that opens the feedback dialog with
 * the context of the current page.
 */
#[Autoconfigure(public: true)]
final readonly class FeedbackSubModule implements ModuleInterface, DataProviderInterface, ContentProviderInterface
{
    public function __construct(
        private DialogConfigurationBuilder $dialogConfigurationBuilder,
        private LanguageServiceFactory $languageServiceFactory,
        private Context $context,
        private GeneralInformation $generalInformation,
    ) {}

    public function getIdentifier(): string
    {
        return 'opinion_feedback';
    }

    public function getLabel(): string
    {
        return $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser())->sL('opinion.messages:dialog.title');
    }

    public function getDataToStore(ServerRequestInterface $request, ResponseInterface $response): ModuleData
    {
        $routing = $request->getAttribute('routing');
        $site = $request->getAttribute('site');
        $language = $request->getAttribute('language');

        return new ModuleData([
            'pageUid' => $routing instanceof PageArguments ? $routing->getPageId() : 0,
            'siteIdentifier' => $site instanceof Site ? $site->getIdentifier() : '',
            'languageId' => $language instanceof SiteLanguage ? $language->getLanguageId() : 0,
            'workspaceId' => (int)$this->context->getPropertyFromAspect('workspace', 'id', 0),
            'pageInfo' => $this->collectPageInfo($request, $response),
        ]);
    }

    public function getContent(ModuleData $data): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return '';
        }

        $configuration = $this->dialogConfigurationBuilder->build($backendUser, Source::Frontend, $data->getArrayCopy());
        $languageService = $this->languageServiceFactory->createFromUserPreferences($backendUser);

        if (is_array($configuration['unavailable'] ?? null)) {
            return sprintf(
                '<p>%s</p><a class="typo3-adminPanel-btn typo3-adminPanel-btn-default" href="%s" target="_blank" rel="noopener">%s</a>',
                htmlspecialchars($configuration['unavailable']['message']),
                htmlspecialchars($configuration['unavailable']['settingsUrl']),
                htmlspecialchars($configuration['unavailable']['settingsLabel']),
            );
        }

        return sprintf(
            '<p>%s</p><button type="button" class="typo3-adminPanel-btn typo3-adminPanel-btn-primary" data-opinion-config="%s">%s</button>',
            htmlspecialchars($languageService->sL('opinion.messages:adminpanel.description')),
            htmlspecialchars(json_encode($configuration, JSON_THROW_ON_ERROR)),
            htmlspecialchars($languageService->sL('opinion.messages:dialog.title')),
        );
    }

    /**
     * The details of the admin panel tab "Info > General". Only its "info" part is
     * taken, the rest contains cookies, server variables and request parameters.
     *
     * @return array<string, mixed>
     */
    private function collectPageInfo(ServerRequestInterface $request, ResponseInterface $response): array
    {
        $info = $this->generalInformation->getDataToStore($request, $response)['info'] ?? [];
        if (!is_array($info)) {
            return [];
        }
        $images = is_array($info['imagesOnPage'] ?? null) ? $info['imagesOnPage'] : [];

        return [
            'pageType' => (int)($info['pageType'] ?? 0),
            'cached' => !(bool)($info['noCache'] ?? false),
            'noCacheReasons' => array_values(array_map(strval(...), (array)($info['noCacheReasons'] ?? []))),
            'uncachedElements' => (int)($info['countUserInt'] ?? 0),
            'parseTimeMs' => (int)($info['totalParsetime'] ?? 0),
            'documentSize' => (string)($info['documentSize'] ?? ''),
            'frontendUser' => [
                'uid' => (int)($info['feUser']['uid'] ?? 0),
                'username' => (string)($info['feUser']['username'] ?? ''),
                'groups' => (string)($info['groupList'] ?? ''),
            ],
            'imagesOnPage' => [
                'total' => (int)($images['total'] ?? 0),
                'totalSize' => (string)($images['totalSize'] ?? ''),
                'files' => array_values(array_map(
                    static fn(array $file): array => ['name' => (string)($file['name'] ?? ''), 'size' => (string)($file['sizeHuman'] ?? '')],
                    array_filter((array)($images['files'] ?? []), is_array(...)),
                )),
            ],
        ];
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
