<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Services;

use GeekCo\FilamentMaxChat\Services\MaxChatSender;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\MaxPhpClient\ApiClient;
use GeekCo\MaxPhpClient\Dto\Recipient;
use GeekCo\MaxPhpClient\Enum\UploadType;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * @phpstan-type ResponseQueue list<Response>
 */
class MaxChatSenderTest extends TestCase
{
    /** @param ResponseQueue $responses */
    private function createSenderWithResponses(array $responses): MaxChatSender
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handler]);

        $api = ApiClient::create(
            httpClient: $httpClient,
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            uriFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            accessToken: 'fake-token',
        );

        return new MaxChatSender($api);
    }

    /** @param array<string, mixed> $data */
    private static function jsonResponse(array $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private static function messageResponse(): Response
    {
        return self::jsonResponse([
            'message' => [
                'recipient' => ['chat_id' => 42, 'user_id' => 1],
                'timestamp' => (int) microtime(true),
            ],
        ]);
    }

    private static function uploadStep1Response(string $token = 'tok123', string $url = 'https://cdn.max.ru/f'): Response
    {
        $data = ['url' => $url];
        if ($token !== '') {
            $data['token'] = $token;
        }

        return self::jsonResponse($data);
    }

    private static function uploadStep2Response(string $token = 'tok123'): Response
    {
        return self::jsonResponse(['token' => $token]);
    }

    private static function deleteResponse(): Response
    {
        return self::jsonResponse(['success' => true]);
    }

    private static function errorResponse(int $code = 500): Response
    {
        return self::jsonResponse(['error' => 'Internal error']);
    }

    /** @return ResponseQueue */
    private function attachmentResponses(string $token = 'tok123'): array
    {
        return [
            self::uploadStep1Response($token),
            self::uploadStep2Response($token),
            self::messageResponse(),
        ];
    }

    #[Test]
    public function send_formatted_sends_html_message(): void
    {
        $sender = $this->createSenderWithResponses([self::messageResponse()]);

        $sender->sendFormatted(
            new Recipient(chatId: 42, userId: 1),
            '<b>hi</b>',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_throws_for_nonexistent_file(): void
    {
        $sender = $this->createSenderWithResponses([]);

        $this->expectException(\RuntimeException::class);
        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::File],
            ['/nonexistent/path/file.bin'],
        );
    }

    #[Test]
    public function send_attachment_throws_for_unreadable_file(): void
    {
        $sender = $this->createSenderWithResponses([]);

        $this->expectException(\RuntimeException::class);
        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::File],
            ['/tmp/definitely-not-readable-'.mt_rand().'.bin'],
        );
    }

    #[Test]
    public function delete_message_delegates_to_api(): void
    {
        $sender = $this->createSenderWithResponses([self::deleteResponse()]);

        $sender->deleteMessage('msg-123');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_with_image_type(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'fake image data');

        $sender = $this->createSenderWithResponses($this->attachmentResponses('img-token'));

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::Image],
            [$tmp],
            'Photo caption',
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_with_video_type(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'fake video data');

        $sender = $this->createSenderWithResponses($this->attachmentResponses('vid-token'));

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::Video],
            [$tmp],
            'Video',
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_with_audio_type(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'fake audio data');

        $sender = $this->createSenderWithResponses($this->attachmentResponses('aud-token'));

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::Audio],
            [$tmp],
            'Audio',
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_without_caption(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'file data');

        $sender = $this->createSenderWithResponses($this->attachmentResponses());

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::File],
            [$tmp],
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_with_empty_caption(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'data');

        $sender = $this->createSenderWithResponses($this->attachmentResponses());

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::File],
            [$tmp],
            '',
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_with_file_type(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'document data');

        $sender = $this->createSenderWithResponses($this->attachmentResponses('file-token'));

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::File],
            [$tmp],
            'Document',
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_uses_url_when_token_is_null(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'data');

        $sender = $this->createSenderWithResponses([
            self::uploadStep1Response('', 'https://cdn.max.ru/direct'),
            self::uploadStep2Response(''),
            self::messageResponse(),
        ]);

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::File],
            [$tmp],
            'Caption',
        );

        @unlink($tmp);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_attachment_logs_and_rethrows_on_upload_failure(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmp, 'data');

        $sender = $this->createSenderWithResponses([
            self::errorResponse(500),
        ]);

        $this->expectException(\Throwable::class);
        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::Image],
            [$tmp],
            'Test',
        );

        @unlink($tmp);
    }

    #[Test]
    public function send_attachments_sends_multiple_media_and_single_message(): void
    {
        $tmpA = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpA, 'image data');
        $tmpB = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpB, 'document data');

        $sender = $this->createSenderWithResponses([
            self::uploadStep1Response('tok-a'),
            self::uploadStep2Response('tok-a'),
            self::uploadStep1Response('tok-b'),
            self::uploadStep2Response('tok-b'),
            self::messageResponse(),
        ]);

        $sender->sendAttachments(
            new Recipient(chatId: 1, userId: 1),
            [UploadType::Image, UploadType::File],
            [$tmpA, $tmpB],
            'Two files',
        );

        @unlink($tmpA);
        @unlink($tmpB);
        $this->expectNotToPerformAssertions();
    }
}
