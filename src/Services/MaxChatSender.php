<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use GeekCo\MaxPhpClient\Enum\TextFormat;
use GeekCo\MaxPhpClient\Enum\UploadType;
use Illuminate\Support\Facades\Log;

/**
 * Sends operator replies to MAX: HTML messages and attachments.
 */
class MaxChatSender
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Sends a formatted HTML reply (bold, italic, links, etc.).
     */
    public function sendFormatted(Recipient $recipient, string $html): void
    {
        $this->api->sendMessage(
            recipient: $recipient,
            body: NewMessageBody::create(text: $html, format: TextFormat::Html),
        );
    }

    /**
     * Sends a reply with an attachment (photo/video/audio/file) and caption.
     */
    public function sendAttachment(
        Recipient $recipient,
        UploadType $type,
        string $absolutePath,
        ?string $caption = null,
    ): void {
        $this->api->sendMessage(
            recipient: $recipient,
            body: NewMessageBody::create(
                text: $caption,
                attachments: [$this->uploadToAttachment($type, $absolutePath)],
                format: $caption !== null && $caption !== '' ? TextFormat::Html : null,
            ),
        );
    }

    /**
     * Deletes a message from MAX by its identifier.
     */
    public function deleteMessage(string $messageId): void
    {
        $this->api->deleteMessage($messageId);
    }

    private function uploadToAttachment(UploadType $type, string $absolutePath): AttachmentRequest
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new \RuntimeException('Media file not found or not readable: '.$absolutePath);
        }

        try {
            $result = $this->api->uploadMedia($type, $absolutePath);
        } catch (\Throwable $e) {
            Log::error('MAX media upload failed', [
                'type' => $type->value,
                'path' => $absolutePath,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'previous' => $e->getPrevious()?->getMessage(),
            ]);

            throw $e;
        }

        return AttachmentRequest::create(
            type: $this->attachmentTypeFor($type),
            token: $result->token,
            url: $result->token === null ? $result->url : null,
        );
    }

    private function attachmentTypeFor(UploadType $type): AttachmentType
    {
        return match ($type) {
            UploadType::Image => AttachmentType::Image,
            UploadType::Video => AttachmentType::Video,
            UploadType::Audio => AttachmentType::Audio,
            UploadType::File => AttachmentType::File,
        };
    }
}
