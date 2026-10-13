<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Processing;

use Vkemeter\Opinion\Delivery\DeliveryResult;
use Vkemeter\Opinion\Domain\DeliveryStatus;

final readonly class ProcessingResult
{
    /**
     * @param list<DeliveryResult> $deliveryResults
     */
    public function __construct(
        public int $feedbackUid,
        public DeliveryStatus $status,
        public array $deliveryResults,
    ) {}
}
