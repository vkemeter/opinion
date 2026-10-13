<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Collection;

/**
 * Every detail an admin can switch off in the extension configuration
 * ("collect_<section>_<name>"). The sender is not listed, it is always kept.
 */
enum CollectableField: string
{
    case ClientUrl = 'client.url';
    case ClientTitle = 'client.title';
    case ClientUserAgent = 'client.userAgent';
    case ClientUserAgentData = 'client.userAgentData';
    case ClientLanguage = 'client.language';
    case ClientLanguages = 'client.languages';
    case ClientTimezone = 'client.timezone';
    case ClientScreen = 'client.screen';
    case ClientViewport = 'client.viewport';
    case ClientDevicePixelRatio = 'client.devicePixelRatio';
    case ClientColorScheme = 'client.colorScheme';
    case ClientReducedMotion = 'client.reducedMotion';
    case ClientOnline = 'client.online';
    case ClientTimestamp = 'client.timestamp';

    case SystemTypo3Version = 'system.typo3Version';
    case SystemApplicationContext = 'system.applicationContext';
    case SystemPhpVersion = 'system.phpVersion';
    case SystemIpAddress = 'system.ipAddress';
    case SystemRequestUserAgent = 'system.requestUserAgent';

    case ContextPage = 'context.page';
    case ContextSite = 'context.site';
    case ContextLanguage = 'context.language';
    case ContextWorkspace = 'context.workspace';
    case ContextModule = 'context.module';
    case ContextUrl = 'context.url';
    case ContextPageType = 'context.pageType';
    case ContextCacheState = 'context.cacheState';
    case ContextUncachedElements = 'context.uncachedElements';
    case ContextParseTime = 'context.parseTime';
    case ContextDocumentSize = 'context.documentSize';
    case ContextFrontendUser = 'context.frontendUser';
    case ContextImagesOnPage = 'context.imagesOnPage';

    public function getConfigurationKey(): string
    {
        return 'collect_' . str_replace('.', '_', $this->value);
    }

    public function getSection(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /**
     * Keys inside the section's data that belong to this field.
     *
     * @return list<list<string>>
     */
    public function getPaths(): array
    {
        $name = explode('.', $this->value, 2)[1];

        return match ($this) {
            self::ContextPage => [['pageUid'], ['pageTitle']],
            self::ContextSite => [['siteIdentifier']],
            self::ContextLanguage => [['languageId'], ['language']],
            self::ContextWorkspace => [['workspaceId']],
            self::ContextCacheState => [['pageInfo', 'cached'], ['pageInfo', 'noCacheReasons']],
            self::ContextParseTime => [['pageInfo', 'parseTimeMs']],
            self::ContextPageType, self::ContextUncachedElements, self::ContextDocumentSize,
            self::ContextFrontendUser, self::ContextImagesOnPage => [['pageInfo', $name]],
            default => [[$name]],
        };
    }
}
