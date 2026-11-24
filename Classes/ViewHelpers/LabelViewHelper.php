<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\ViewHelpers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Formatting\ConfiguredLabels;

/**
 * Label of a configured value in the language f:translate would use:
 * {opinion:label(list: 'category', value: item.category)}
 */
final class LabelViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly ConfiguredLabels $configuredLabels,
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('list', 'string', 'Name of the configured list, e.g. category', true);
        $this->registerArgument('value', 'string', 'Stored value', true);
    }

    public function render(): string
    {
        // Same request as f:translate: the one of the rendering context, otherwise the global one
        $request = $this->renderingContext?->hasAttribute(ServerRequestInterface::class) === true ? $this->renderingContext->getAttribute(ServerRequestInterface::class) : ($GLOBALS['TYPO3_REQUEST'] ?? null);

        return $this->configuredLabels->labelForRequest(
            (string)$this->arguments['list'],
            (string)$this->arguments['value'],
            $this->configurationProvider->getConfiguration(),
            $request instanceof ServerRequestInterface ? $request : null,
        );
    }
}
