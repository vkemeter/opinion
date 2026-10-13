<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Context;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Vkemeter\Opinion\Domain\Source;

/**
 * Implemented by the entry points (admin panel, backend toolbar) to validate the
 * context they send along, e.g. that the page exists. Context of a source
 * without a provider is dropped.
 */
#[AutoconfigureTag('opinion.context_provider')]
interface ContextProviderInterface
{
    public function supports(Source $source): bool;

    /**
     * @param array<string, mixed> $context as sent by the browser
     * @return array<string, mixed> validated context
     */
    public function resolve(array $context, ServerRequestInterface $request): array;
}
