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
     * @param list<string> $linkableHosts lowercase, see LinkableHosts; other hosts are not linked
     * @param bool $allowPath link a path like /typo3/module/...; only works on the same host, not in an email
     * @return array{url: string, linked: bool}|null linked = false: show as text only
     */
    public static function fromDetails(array $clientInfo, array $context, array $linkableHosts, bool $allowPath): ?array
    {
        foreach ([$clientInfo['url'] ?? null, $context['url'] ?? null] as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            // Browsers read a backslash as slash: /\evil.example opens //evil.example, and
            // https://evil.example\@example.com opens evil.example while parse_url() sees example.com
            if (str_contains($url, '\\')) {
                return ['url' => $url, 'linked' => false];
            }
            $parts = parse_url($url);
            $isOwnWebUrl = is_array($parts)
                && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                && in_array(strtolower($parts['host'] ?? ''), $linkableHosts, true);
            $isPath = str_starts_with($url, '/') && !str_starts_with($url, '//');

            return ['url' => $url, 'linked' => $isOwnWebUrl || ($allowPath && $isPath)];
        }

        return null;
    }
}
