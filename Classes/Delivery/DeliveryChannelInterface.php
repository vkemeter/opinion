<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Delivery;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Resource\File;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Domain\Submission;

/**
 * A way to hand a stored submission to the team, e.g. email or a ticket system.
 * Implementations report failures through the result instead of throwing.
 */
#[AutoconfigureTag('opinion.delivery_channel')]
interface DeliveryChannelInterface
{
    public function getIdentifier(): string;

    public function deliver(Submission $submission, ?File $screenshot, OpinionConfiguration $configuration): DeliveryResult;
}
