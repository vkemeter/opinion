<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;

/**
 * Badge variant of a processing status by its role: {opinion:statusBadge(value: item.status)}
 * gives info for the initial status, success for closed statuses and warning otherwise.
 */
final class StatusBadgeViewHelper extends AbstractViewHelper
{
    public function __construct(
        private readonly ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'string', 'Stored status', true);
    }

    public function render(): string
    {
        $configuration = $this->configurationProvider->getConfiguration();
        $status = (string)$this->arguments['value'];

        return match (true) {
            $status === $configuration->initialStatus() => 'info',
            $configuration->isClosed($status) => 'success',
            default => 'warning',
        };
    }
}
