<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Dashboard\Widgets\ButtonProviderInterface;

/**
 * Button to the module "Feedback"; the module URL carries a token, so it is built at render time.
 */
final readonly class ModuleButtonProvider implements ButtonProviderInterface
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private string $title = 'opinion.messages:dashboard.button',
    ) {}

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLink(): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(FeedbackScope::MODULE);
    }

    public function getTarget(): string
    {
        return '';
    }
}
