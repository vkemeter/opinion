<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

use TYPO3\CMS\Core\Resource\File;

final readonly class Submission
{
    /**
     * @param array<string, mixed> $clientInfo whitelisted data collected by the browser
     * @param array<string, mixed> $systemInfo data collected on the server
     * @param array<string, mixed> $context data contributed by the entry point, validated on the server
     * @param array<string, mixed> $contextAtEnd context at the end of a backend recording, validated like $context
     * @param string $backendUrl base URL of the backend the feedback was sent from, '' = unknown
     * @param int $feedbackUid uid of the stored record, 0 before storing
     * @param File|null $videoFile the stored video, for delivery channels
     * @param list<array{number: int, comment: string}> $pins comments of the pins on the screenshot, sorted by number
     */
    public function __construct(
        public string $message,
        public string $category,
        public Severity $severity,
        public Source $source,
        public int $backendUserUid,
        public \DateTimeImmutable $createdAt,
        public array $clientInfo = [],
        public array $systemInfo = [],
        public array $context = [],
        public ?Screenshot $screenshot = null,
        public ?Video $video = null,
        public array $contextAtEnd = [],
        public string $backendUrl = '',
        public int $feedbackUid = 0,
        public ?File $videoFile = null,
        public array $pins = [],
    ) {}

    /**
     * The submission as delivery channels get it: with the uid of its record and the stored video.
     */
    public function withStoredFeedback(int $feedbackUid, ?File $videoFile): self
    {
        return new self(
            $this->message,
            $this->category,
            $this->severity,
            $this->source,
            $this->backendUserUid,
            $this->createdAt,
            $this->clientInfo,
            $this->systemInfo,
            $this->context,
            $this->screenshot,
            $this->video,
            $this->contextAtEnd,
            $this->backendUrl,
            $feedbackUid,
            $videoFile,
            $this->pins,
        );
    }
}
