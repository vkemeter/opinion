<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * The screenshot storage lives in var/opinion/, outside the web root. It is
 * configured relative to the public path, so the path stays valid when the
 * project directory changes (e.g. a new deployment release).
 */
final readonly class ScreenshotStorageLocation
{
    public const string STORAGE_NAME = 'Opinion screenshots';

    public function getAbsolutePath(): string
    {
        return Environment::getVarPath() . '/opinion/';
    }

    /**
     * @return non-empty-string
     */
    public function getPathRelativeToPublicPath(): string
    {
        $sourceSegments = explode('/', trim(PathUtility::getCanonicalPath(Environment::getPublicPath()), '/'));
        $targetSegments = explode('/', trim(PathUtility::getCanonicalPath($this->getAbsolutePath()), '/'));

        while ($sourceSegments !== [] && $targetSegments !== [] && $sourceSegments[0] === $targetSegments[0]) {
            array_shift($sourceSegments);
            array_shift($targetSegments);
        }

        return str_repeat('../', count($sourceSegments)) . implode('/', $targetSegments) . '/';
    }

    /**
     * @param array<string, mixed> $driverConfiguration
     */
    public function matches(array $driverConfiguration): bool
    {
        $basePath = (string)($driverConfiguration['basePath'] ?? '');
        if ($basePath === '') {
            return false;
        }
        if (($driverConfiguration['pathType'] ?? '') === 'relative') {
            $basePath = Environment::getPublicPath() . '/' . $basePath;
        }

        return PathUtility::getCanonicalPath($basePath) === PathUtility::getCanonicalPath($this->getAbsolutePath());
    }
}
