<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Collector;

/**
 * Reduces the client info sent by the browser to a fixed set of keys with
 * known types and lengths. Everything else (cookies, storage contents, …)
 * is dropped.
 */
final readonly class ClientInfoSanitizer
{
    /**
     * @see SchemaSanitizer for the notation
     */
    private const array SCHEMA = [
        'url' => 'url',
        'title' => 'string:300',
        'userAgent' => 'string:512',
        'userAgentData' => [
            'brands' => [['brand' => 'string:100', 'version' => 'string:50']],
            'platform' => 'string:100',
            'mobile' => 'bool',
        ],
        'language' => 'string:35',
        'languages' => ['string:35'],
        'timezone' => 'string:64',
        'screen' => ['width' => 'int', 'height' => 'int', 'colorDepth' => 'int'],
        'viewport' => ['width' => 'int', 'height' => 'int'],
        'devicePixelRatio' => 'float',
        'colorScheme' => 'enum:light|dark|no-preference',
        'reducedMotion' => 'bool',
        'online' => 'bool',
        'timestamp' => 'string:40',
    ];

    public function __construct(
        private SchemaSanitizer $schemaSanitizer,
    ) {}

    /**
     * @param array<array-key, mixed> $clientInfo
     * @return array<string, mixed>
     */
    public function sanitize(array $clientInfo): array
    {
        return $this->schemaSanitizer->sanitize($clientInfo, self::SCHEMA);
    }
}
