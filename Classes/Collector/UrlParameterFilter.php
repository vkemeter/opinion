<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Collector;

/**
 * Removes query and fragment parameters from a collected URL, so preview, login or session
 * parameters do not end up in the email and the database.
 */
final readonly class UrlParameterFilter
{
    /**
     * Always removed, independent of the configuration: the request token of
     * backend URLs is not needed for reproducing a problem.
     */
    private const array ALWAYS_DENIED = ['token'];

    /**
     * @param list<string> $deniedParameters parameter names, case-insensitive; a trailing `*` matches a prefix
     */
    public function filter(string $url, array $deniedParameters): string
    {
        $patterns = array_map(strtolower(...), [...self::ALWAYS_DENIED, ...$deniedParameters]);
        $fragmentStart = strpos($url, '#');
        $fragment = $fragmentStart !== false ? substr($url, $fragmentStart + 1) : null;
        $url = $fragmentStart !== false ? substr($url, 0, $fragmentStart) : $url;

        $queryStart = strpos($url, '?');
        if ($queryStart !== false) {
            $query = $this->filterPairs(substr($url, $queryStart + 1), $patterns);
            $url = substr($url, 0, $queryStart) . ($query !== '' ? '?' . $query : '');
        }

        if ($fragment !== null) {
            // Tokens also come in fragments (#access_token=…, #/reset?token=…); anchors and routes have no denied pair
            $routeEnd = strpos($fragment, '?');
            if ($routeEnd !== false) {
                $query = $this->filterPairs(substr($fragment, $routeEnd + 1), $patterns);
                $fragment = substr($fragment, 0, $routeEnd) . ($query !== '' ? '?' . $query : '');
            } else {
                $fragment = $this->filterPairs($fragment, $patterns);
            }
            $url .= $fragment !== '' ? '#' . $fragment : '';
        }

        return $url;
    }

    /**
     * The pairs are kept as sent, so the URL stays identical apart from the removed parameters.
     *
     * @param list<string> $patterns lowercase
     */
    private function filterPairs(string $pairs, array $patterns): string
    {
        return implode('&', array_filter(
            explode('&', $pairs),
            fn(string $pair): bool => $pair !== '' && !$this->isDenied($this->getParameterName($pair), $patterns),
        ));
    }

    /**
     * The name before `=` and before a `[`, so `tx_felogin_login[hash]` is matched by `tx_felogin_login`.
     */
    private function getParameterName(string $pair): string
    {
        $name = urldecode(explode('=', $pair, 2)[0]);
        $bracket = strpos($name, '[');

        return strtolower($bracket !== false ? substr($name, 0, $bracket) : $name);
    }

    /**
     * @param list<string> $patterns lowercase
     */
    private function isDenied(string $name, array $patterns): bool
    {
        return array_any($patterns, fn($pattern) => str_ends_with($pattern, '*') ? str_starts_with($name, substr($pattern, 0, -1)) : $name === $pattern);
    }
}
