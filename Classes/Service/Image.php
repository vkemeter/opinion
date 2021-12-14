<?php

declare(strict_types=1);

namespace Supseven\Opinion\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Image
{
    /**
     * @param string $base64
     * @return File
     */
    public static function saveImage(string $base64): File
    {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $storage = $resourceFactory->getDefaultStorage();
        $path = Environment::getPublicPath() . DIRECTORY_SEPARATOR . 'fileadmin' . $storage->getDefaultFolder(
            )->getIdentifier() . DIRECTORY_SEPARATOR;
        $tmpPath = Environment::getPublicPath() . DIRECTORY_SEPARATOR . 'typo3temp' . DIRECTORY_SEPARATOR;

        // needs to be dynamic
        $fileName = 'test' . time() . '.jpg';

        $file = fopen($tmpPath . $fileName, 'wb');
        $data = explode(',', $base64);
        fwrite($file, base64_decode($data[1]));
        fclose($file);

        if (file_exists($tmpPath . $fileName)) {
            $newFile = $storage->addFile(
                $tmpPath . $fileName,
                $storage->getDefaultFolder(),
                $fileName
            );

            return $newFile;
        }
    }
}
