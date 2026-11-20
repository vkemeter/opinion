<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Link;

/**
 * The URL a feedback was sent from, shown once as link in the detail view
 * and the email instead of in the tables of collected details.
 */
final class ReportedUrl
{
    /**
     * The browser URL, or the module URL (path and query) when the browser URL was not collected.
     *
     * @param array<string, mixed> $clientInfo
     * @param array<string, mixed> $context
     * @param bool $allowPath link a path like /typo3/module/...; only works on the same host, not in an email
     * @return array{url: string, linked: bool}|null linked = false: show as text only
     */
    public static function fromDetails(array $clientInfo, array $context, bool $allowPath): ?array
    {
        foreach ([$clientInfo['url'] ?? null, $context['url'] ?? null] as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $parts = parse_url($url);
            $isWebUrl = is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
            $isPath = str_starts_with($url, '/') && !str_starts_with($url, '//');

            return ['url' => $url, 'linked' => $isWebUrl || ($allowPath && $isPath)];
        }

        return null;
    }
}
