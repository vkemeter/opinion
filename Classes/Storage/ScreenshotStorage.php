<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;

/**
 * Stores screenshots in the non-public storage as YYYY/MM/<uuid>.<extension>.
 */
final readonly class ScreenshotStorage
{
    public function __construct(
        private ScreenshotStorageResolver $resolver,
    ) {}

    public function store(string $content, string $fileExtension, \DateTimeImmutable $date): File
    {
        $storage = $this->getStorage();
        $temporaryFile = GeneralUtility::tempnam('opinion_');
        if (GeneralUtility::writeFileToTypo3tempDir($temporaryFile, $content) !== null) {
            throw new ScreenshotStorageException('Temporary screenshot file could not be written.', 1791561468);
        }

        try {
            // Storing happens on behalf of the system, independent of the file mounts of the sender
            return $this->asSystem($storage, fn(): File => $storage->addFile(
                $temporaryFile,
                $this->getTargetFolder($storage, $date),
                Uuid::v4()->toRfc4122() . '.' . $fileExtension,
                DuplicationBehavior::RENAME,
            ));
        } catch (\Throwable $exception) {
            // FAL and the local driver throw various exceptions (permissions, full disk); all mean "not stored"
            throw new ScreenshotStorageException('The screenshot could not be written into the storage: ' . $exception->getMessage(), 1795412871, $exception);
        } finally {
            if (is_file($temporaryFile)) {
                GeneralUtility::unlink_tempfile($temporaryFile);
            }
        }
    }

    public function delete(File $file): void
    {
        $this->asSystem($file->getStorage(), static fn(): bool => $file->getStorage()->deleteFile($file));
    }

    /**
     * Content of the file, read on behalf of the system (the sending editor has no file mount on the storage).
     */
    public function getContents(File $file): string
    {
        return $this->asSystem($file->getStorage(), static fn(): string => $file->getContents());
    }

    /**
     * Inline response with the file; access is checked by the caller (feedback module).
     * A single byte range ("bytes=a-b", "bytes=a-", "bytes=-n") is answered with 206, so a video player can seek.
     *
     * @param string $range value of the Range header, '' = whole file
     */
    public function stream(File $file, string $range = ''): ResponseInterface
    {
        $response = $this->asSystem($file->getStorage(), static fn(): ResponseInterface => $file->getStorage()->streamFile($file))
            ->withHeader('Accept-Ranges', 'bytes');
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) !== 1 || $matches[1] . $matches[2] === '') {
            return $response;
        }

        $size = $file->getSize();
        [$start, $end] = $matches[1] === ''
            ? [max(0, $size - (int)$matches[2]), $size - 1]
            : [(int)$matches[1], $matches[2] === '' ? $size - 1 : min((int)$matches[2], $size - 1)];
        if ($start > $end) {
            return new Response(null, 416, ['Content-Range' => 'bytes */' . $size]);
        }

        $length = $end - $start + 1;
        $path = $this->asSystem($file->getStorage(), static fn(): string => $file->getForLocalProcessing(false));

        return $response
            ->withStatus(206)
            ->withBody(new FileRangeStream($path, $start, $length))
            ->withHeader('Content-Length', (string)$length)
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size));
    }

    public function isAvailable(): bool
    {
        $storage = $this->resolver->findStorage();

        return $storage !== null && !$storage->isPublic() && $storage->isOnline() && $storage->isWritable();
    }

    private function getStorage(): ResourceStorage
    {
        $storage = $this->resolver->findStorage();
        if ($storage === null || !$this->isAvailable()) {
            throw new ScreenshotStorageException('The non-public screenshot storage is missing, public, offline or not writable. Run "vendor/bin/typo3 extension:setup".', 1791561469);
        }

        return $storage;
    }

    private function getTargetFolder(ResourceStorage $storage, \DateTimeImmutable $date): Folder
    {
        $folder = $storage->getRootLevelFolder(false);
        foreach ([$date->format('Y'), $date->format('m')] as $name) {
            $folder = $folder->hasFolder($name) ? $folder->getSubfolder($name) : $folder->createFolder($name);
        }

        return $folder;
    }

    /**
     * Runs the action with the permission checks of the storage disabled and restores them afterwards.
     *
     * @template T
     * @param \Closure(): T $action
     * @return T
     */
    private function asSystem(ResourceStorage $storage, \Closure $action): mixed
    {
        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            return $action();
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }
    }
}
