<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Context;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use Vkemeter\Opinion\Domain\Source;

/**
 * Validates the context the backend toolbar sends: module, page and the URL
 * of the module frame (path and query only).
 */
final readonly class BackendContextProvider implements ContextProviderInterface
{
    private const int MAX_URL_LENGTH = 2048;

    public function __construct(
        private PageInfoResolver $pageInfoResolver,
        private ModuleProvider $moduleProvider,
    ) {}

    public function supports(Source $source): bool
    {
        return $source === Source::Backend;
    }

    public function resolve(array $context, ServerRequestInterface $request): array
    {
        $result = [];

        $module = $context['module'] ?? null;
        if (is_string($module) && $module !== '' && $this->moduleProvider->isModuleRegistered($module)) {
            $result['module'] = $module;
        }

        $result = [...$result, ...($this->pageInfoResolver->resolve($context['pageUid'] ?? null) ?? [])];

        $url = $context['url'] ?? null;
        if (is_string($url) && strlen($url) <= self::MAX_URL_LENGTH) {
            $path = (string)parse_url($url, PHP_URL_PATH);
            $query = (string)parse_url($url, PHP_URL_QUERY);
            if (str_starts_with($path, '/')) {
                // Parameters like the request token are removed by the UrlParameterFilter
                $result['url'] = $path . ($query !== '' ? '?' . $query : '');
            }
        }

        return $result;
    }
}
