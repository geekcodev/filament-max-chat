<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Feature;

use GeekCo\FilamentMaxChat\Enums\MaxMessageDirection;
use GeekCo\FilamentMaxChat\Models\MaxMessage;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\LaravelMaxClient\Webhook\MaxUpdateReceived;
use GeekCo\MaxPhpClient\Dto\Update;
use GeekCo\MaxPhpClient\Webhook\WebhookHandler;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Сквозная проверка регрессии «контакт не доходит до чата» (хост-приложение): в
 * официальной спеке MAX `ContactAttachmentPayload.hash` nullable, а до фикса
 * гидрации ядра (v1.1.11) такой webhook ронял весь апдейт на разборе. Тест идёт
 * реальным путём доставки: сырой JSON → WebhookHandler::decode() ядра →
 * MaxUpdateReceived → слушатель плагина → MaxMessageService::storeIncoming().
 */
class ContactWithoutHashWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_without_hash_reaches_the_feed_as_a_card(): void
    {
        $decoded = (new WebhookHandler())->decode(
            (new HttpFactory())->createRequest('POST', 'https://example.com/webhook')
                ->withBody((new HttpFactory())->createStream(json_encode([
                    'update_type' => 'message_created',
                    'timestamp' => 1_700_000_000_000,
                    'message' => [
                        'sender' => [
                            'user_id' => 111,
                            'first_name' => 'Мария',
                            'last_name' => 'Сидорова',
                            'is_bot' => false,
                            'last_activity_time' => 1_700_000_000_000,
                        ],
                        'recipient' => ['chat_id' => 222, 'user_id' => 111],
                        'timestamp' => 1_700_000_000_000,
                        'body' => [
                            'mid' => 'm-hashless',
                            'seq' => 1,
                            'attachments' => [
                                [
                                    'type' => 'contact',
                                    'payload' => [
                                        'vcf_info' => "BEGIN:VCARD\r\nFN:Мария Сидорова\r\nTEL;TYPE=CELL:+79990001122\r\nEND:VCARD",
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR))),
        );

        $this->assertInstanceOf(Update::class, $decoded);

        event(new MaxUpdateReceived($decoded));

        $message = MaxMessage::query()->sole();
        $this->assertSame(MaxMessageDirection::In, $message->direction);
        $this->assertSame('m-hashless', $message->message_id);

        $attachments = $message->attachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('contact', $attachments[0]['type']);
        $this->assertSame('Мария Сидорова', $attachments[0]['name'] ?? null);
        $this->assertSame('+79990001122', $attachments[0]['phone'] ?? null);
        $this->assertArrayNotHasKey('hash', $attachments[0]);
    }
}
