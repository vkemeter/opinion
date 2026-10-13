<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Context;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use Vkemeter\Opinion\Collector\SchemaSanitizer;
use Vkemeter\Opinion\Domain\Source;

/**
 * Validates the context the admin panel sends: page, site, language and workspace.
 */
final readonly class FrontendContextProvider implements ContextProviderInterface
{
    private const int MAX_IMAGES = 50;

    /**
     * Details of the admin panel tab "Info > General", sent along by the browser.
     *
     * @see \Vkemeter\Opinion\Adminpanel\FeedbackSubModule::collectPageInfo()
     */
    private const array PAGE_INFO_SCHEMA = [
        'pageType' => 'int',
        'cached' => 'bool',
        'noCacheReasons' => ['string:300'],
        'uncachedElements' => 'int',
        'parseTimeMs' => 'int',
        'documentSize' => 'string:30',
        'frontendUser' => ['uid' => 'int', 'username' => 'string:255', 'groups' => 'string:255'],
        'imagesOnPage' => [
            'total' => 'int',
            'totalSize' => 'string:30',
            'files' => [['name' => 'string:1000', 'size' => 'string:30']],
        ],
    ];

    public function __construct(
        private PageInfoResolver $pageInfoResolver,
        private SiteFinder $siteFinder,
        private SchemaSanitizer $schemaSanitizer,
    ) {}

    public function supports(Source $source): bool
    {
        return $source === Source::Frontend;
    }

    public function resolve(array $context, ServerRequestInterface $request): array
    {
        $result = $this->pageInfoResolver->resolve($context['pageUid'] ?? null) ?? [];

        $siteIdentifier = $context['siteIdentifier'] ?? null;
        if (is_string($siteIdentifier) && $siteIdentifier !== '') {
            try {
                $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
                $result['siteIdentifier'] = $site->getIdentifier();

                $languageId = filter_var($context['languageId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($languageId !== false && in_array($languageId, array_map(static fn($language): int => $language->getLanguageId(), $site->getAllLanguages()), true)) {
                    $result['languageId'] = $languageId;
                    $result['language'] = $site->getLanguageById($languageId)->getTitle();
                }
            } catch (SiteNotFoundException) {
            }
        }

        $workspaceId = filter_var($context['workspaceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($workspaceId !== false) {
            $result['workspaceId'] = $workspaceId;
        }

        if (is_array($context['pageInfo'] ?? null)) {
            $pageInfo = $this->schemaSanitizer->sanitize($context['pageInfo'], self::PAGE_INFO_SCHEMA, self::MAX_IMAGES);
            if ($pageInfo !== []) {
                $result['pageInfo'] = $pageInfo;
            }
        }

        return $result;
    }
}
