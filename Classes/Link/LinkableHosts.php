<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Link;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The hosts a reported URL may link to: the own sites and the current request,
 * so a sender cannot place a link to a foreign site in front of the recipients.
 */
final readonly class LinkableHosts
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @param ServerRequestInterface|null $request null without request (CLI): the site hosts only
     * @return list<string> lowercase
     */
    public function forRequest(?ServerRequestInterface $request): array
    {
        $hosts = $request !== null ? [$request->getUri()->getHost()] : [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $hosts[] = $site->getBase()->getHost();
            foreach ($site->getAllLanguages() as $language) {
                $hosts[] = $language->getBase()->getHost();
            }
        }

        // Relative bases like / have no host
        return array_values(array_unique(array_filter(array_map(strtolower(...), $hosts), static fn(string $host): bool => $host !== '')));
    }
}
