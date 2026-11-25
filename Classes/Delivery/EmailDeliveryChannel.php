<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Delivery;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Formatting\ConfiguredLabels;
use Vkemeter\Opinion\Formatting\DataFlattener;
use Vkemeter\Opinion\Link\LinkableHosts;
use Vkemeter\Opinion\Link\ReportedUrl;
use Vkemeter\Opinion\Storage\ScreenshotStorage;

final readonly class EmailDeliveryChannel implements DeliveryChannelInterface
{
    private const int SUBJECT_MESSAGE_LENGTH = 60;

    public function __construct(
        private MailerInterface $mailer,
        private DataFlattener $dataFlattener,
        private ScreenshotStorage $screenshotStorage,
        private LinkableHosts $linkableHosts,
        private ConfiguredLabels $configuredLabels,
    ) {}

    public function getIdentifier(): string
    {
        return 'email';
    }

    public function deliver(Submission $submission, ?File $screenshot, OpinionConfiguration $configuration): DeliveryResult
    {
        if ($configuration->recipients === []) {
            return DeliveryResult::failure($this->getIdentifier(), 'No valid recipient configured in the extension configuration.');
        }

        $sender = $submission->systemInfo['backendUser'] ?? [];
        $sender = is_array($sender) ? $sender : [];

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $request = $request instanceof ServerRequestInterface ? $request : null;
        $email = new FluidEmail();
        $email
            ->to(...$configuration->recipients)
            ->from(new Address(MailUtility::getSystemFromAddress(), MailUtility::getSystemFromName() ?? ''))
            ->subject($this->buildSubject($submission, $configuration, $this->configuredLabels->labelForRequest('category', $submission->category, $configuration, $request)))
            ->format(FluidEmail::FORMAT_BOTH)
            ->setTemplate('Opinion/Feedback')
            ->assignMultiple([
                'submission' => $submission,
                'sender' => $sender,
                // Site hosts only: the host of the submit request is set by the sender (Host header)
                'reportedUrl' => ReportedUrl::fromDetails($submission->clientInfo, $submission->context, $this->linkableHosts->forRequest(null), false),
                // The URLs are shown once as reportedUrl
                'clientInfo' => $this->dataFlattener->flatten(array_diff_key($submission->clientInfo, ['url' => true])),
                'systemInfo' => $this->dataFlattener->flatten(array_diff_key($submission->systemInfo, ['backendUser' => true])),
                'context' => $this->dataFlattener->flatten(array_diff_key($submission->context, ['url' => true])),
                // Only where the recording ended elsewhere
                'contextAtEnd' => $submission->contextAtEnd !== $submission->context ? $this->dataFlattener->flatten($submission->contextAtEnd) : [],
                'hasScreenshot' => $screenshot instanceof File,
                'video' => $this->buildVideoLink($submission),
            ]);

        $senderEmail = (string)($sender['email'] ?? '');
        if (GeneralUtility::validEmail($senderEmail)) {
            $senderName = (string)($sender['realName'] ?? '');
            $email->replyTo(new Address($senderEmail, $senderName !== '' ? $senderName : (string)($sender['username'] ?? '')));
        }

        if ($screenshot instanceof File) {
            $content = $this->screenshotStorage->getContents($screenshot);
            $email
                ->embed($content, 'screenshot', $screenshot->getMimeType())
                ->attach($content, 'screenshot.' . $screenshot->getExtension(), $screenshot->getMimeType());
        }

        if ($request !== null) {
            $email->setRequest($request);
        }

        $this->mailer->send($email);

        return DeliveryResult::success($this->getIdentifier(), 'sent to ' . implode(', ', $configuration->recipients));
    }

    /**
     * Videos are never attached (size), the email links the detail view in the feedback module instead.
     *
     * @return array{size: string, url: string}|null url '' when the backend URL is unknown
     */
    private function buildVideoLink(Submission $submission): ?array
    {
        if (!$submission->videoFile instanceof File) {
            return null;
        }

        return [
            'size' => number_format($submission->videoFile->getSize() / 1024 / 1024, 1),
            'url' => $submission->backendUrl !== '' && $submission->feedbackUid > 0
                ? $submission->backendUrl . '/module/opinion/feedback/show?uid=' . $submission->feedbackUid
                : '',
        ];
    }

    /**
     * @param string $categoryLabel label of the category in the language of the email body
     */
    public function buildSubject(Submission $submission, OpinionConfiguration $configuration, string $categoryLabel): string
    {
        $message = trim((string)preg_replace('/\s+/u', ' ', $submission->message));
        if (mb_strlen($message) > self::SUBJECT_MESSAGE_LENGTH) {
            $message = rtrim(mb_substr($message, 0, self::SUBJECT_MESSAGE_LENGTH)) . '…';
        }

        return ltrim(sprintf('%s [%s/%s] %s', $configuration->subjectPrefix, $categoryLabel, $submission->severity->value, $message));
    }
}
