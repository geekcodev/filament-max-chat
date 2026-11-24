<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest\AudioAttachmentRequest;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest\FileAttachmentRequest;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest\InlineKeyboardAttachmentRequest;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest\PhotoAttachmentRequest;
use GeekCo\MaxPhpClient\Dto\AttachmentRequest\VideoAttachmentRequest;
use GeekCo\MaxPhpClient\Dto\Button\RequestContactButton;
use GeekCo\MaxPhpClient\Dto\InlineKeyboardAttachmentRequestPayload;
use GeekCo\MaxPhpClient\Dto\NewMessageBody;
use GeekCo\MaxPhpClient\Dto\NewMessageLink;
use GeekCo\MaxPhpClient\Dto\PhotoAttachmentRequestPayload;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Dto\UploadedInfo;
use GeekCo\MaxPhpClient\Enum\MessageLinkType;
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
    public function sendFormatted(Recipient $recipient, string $html, ?string $replyToMessageId = null): void
    {
        $this->api->sendMessage(
            recipient: $recipient,
            body: NewMessageBody::create(
                text: $html,
                link: $this->replyLink($replyToMessageId),
                format: TextFormat::Html,
            ),
        );
    }

    /**
     * Sends a reply with one or more attachments and a caption.
     *
     * @param list<UploadType> $types
     * @param list<string>     $paths absolute paths, aligned with $types by index
     */
    public function sendAttachments(
        Recipient $recipient,
        array $types,
        array $paths,
        ?string $caption = null,
        ?string $replyToMessageId = null,
    ): void {
        $attachments = [];

        foreach ($types as $index => $type) {
            $attachments[] = $this->uploadToAttachment($type, $paths[$index]);
        }

        $this->api->sendMessage(
            recipient: $recipient,
            body: NewMessageBody::create(
                text: $caption,
                attachments: $attachments,
                link: $this->replyLink($replyToMessageId),
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

    /**
     * Sends an inline keyboard with a request_contact button. MAX rejects a keyboard message
     * without text (errors.required), so when no text is given the button caption is used.
     */
    public function sendRequestContact(Recipient $recipient, string $buttonText = 'Поделиться контактом', ?string $text = null): void
    {
        $button = new RequestContactButton($buttonText);
        $attachment = new InlineKeyboardAttachmentRequest(
            new InlineKeyboardAttachmentRequestPayload([[$button]]),
        );
        $hasText = $text !== null && $text !== '';

        $this->api->sendMessage(
            recipient: $recipient,
            body: NewMessageBody::create(
                text: $hasText ? $text : $buttonText,
                attachments: [$attachment],
                format: $hasText ? TextFormat::Html : null,
            ),
        );
    }

    private function replyLink(?string $replyToMessageId): ?NewMessageLink
    {
        if ($replyToMessageId === null || $replyToMessageId === '') {
            return null;
        }

        return new NewMessageLink(MessageLinkType::Reply, $replyToMessageId);
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

        if ($type === UploadType::Image) {
            return new PhotoAttachmentRequest(new PhotoAttachmentRequestPayload(
                token: $result->token,
                url: $result->token === null ? $result->url : null,
            ));
        }

        $token = $result->token;

        if ($token === null) {
            throw new \RuntimeException('MAX upload did not return an attachment token.');
        }

        $payload = new UploadedInfo($token);

        return match ($type) {
            UploadType::Video => new VideoAttachmentRequest($payload),
            UploadType::Audio => new AudioAttachmentRequest($payload),
            UploadType::File => new FileAttachmentRequest($payload),
        };
    }
}
