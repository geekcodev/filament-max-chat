<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Services;

use GeekCo\FilamentMaxChat\Services\MaxAttachmentStore;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\MaxPhpClient\Dto\Attachment\AudioAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\ContactAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\FileAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\InlineKeyboardAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\LocationAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\PhotoAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\ShareAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\StickerAttachment;
use GeekCo\MaxPhpClient\Dto\Attachment\VideoAttachment;
use GeekCo\MaxPhpClient\Dto\Button\CallbackButton;
use GeekCo\MaxPhpClient\Dto\Button\LinkButton;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\FileAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\Keyboard;
use GeekCo\MaxPhpClient\Dto\MediaAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\PhotoAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\ShareAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\StickerAttachmentPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MaxAttachmentStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_downloads_image_and_stores_on_local_disk(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'https://cdn.max.ru/photo.png')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayHasKey('mime', $meta);
        $this->assertArrayHasKey('size', $meta);
        $this->assertArrayHasKey('path', $meta);
        $this->assertSame('image', $meta['type']);
        $this->assertSame('image/png', $meta['mime']);
        $this->assertSame(strlen('png-bytes'), $meta['size']);
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_failed_download_returns_type_only_meta(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('nope', 404),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'https://cdn.max.ru/missing.jpg')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('image', $meta['type']);
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_oversized_download_is_rejected(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response(str_repeat('a', 100), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        config()->set('filament-max-chat.attachments.max_bytes', 10);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'https://cdn.max.ru/big.jpg')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_extension_is_taken_from_url_when_content_type_is_generic(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('doc-bytes', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://cdn.max.ru/media/b2f/report.pdf', token: 'file-token')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('path', $meta);
        $this->assertSame('report.pdf', $meta['name']);
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_mime_is_sniffed_from_content_when_cdn_sends_octet_stream(): void
    {
        Storage::fake('local');
        Http::fake([
            'fd.oneme.ru/*' => Http::response("%PDF-1.4\n%fake-pdf-content", 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://fd.oneme.ru/getfile?rq=abc&expires=123', token: 'file-token')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayHasKey('mime', $meta);
        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('path', $meta);
        $this->assertSame('application/pdf', $meta['mime']);
        $this->assertSame('file.pdf', $meta['name']);
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_video_attachment_stores_type_only_without_download(): void
    {
        Storage::fake('local');
        Http::fake();

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new VideoAttachment(new MediaAttachmentPayload(url: 'https://cdn.max.ru/video.mp4', token: 'video-token')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('video', $meta['type']);
        $this->assertArrayNotHasKey('path', $meta);
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_no_media_attachments_returns_empty_list(): void
    {
        $this->assertSame([], app(MaxAttachmentStore::class)->storeFromIncoming([
            new InlineKeyboardAttachment(new Keyboard([])),
        ]));
        $this->assertSame([], app(MaxAttachmentStore::class)->storeFromIncoming(null));
    }

    public function test_store_from_upload_persists_file_with_original_name(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');

        $meta = app(MaxAttachmentStore::class)->storeFromUpload($uploaded);

        $this->assertSame('file', $meta['type']);
        $this->assertSame('report.pdf', $meta['name']);
        $this->assertSame('application/pdf', $meta['mime']);
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_store_from_upload_fails_loudly_when_the_source_cannot_be_read(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->create('report.pdf', 10, 'application/pdf');
        $path = $uploaded->getRealPath();

        $this->assertIsString($path);
        chmod($path, 0o000);

        // Шум от fopen глушим, иначе обработчик Laravel превратит warning в
        // ErrorException раньше, чем сработает проверка на $stream === false.
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to open file for reading');

            app(MaxAttachmentStore::class)->storeFromUpload($uploaded);
        } finally {
            restore_error_handler();
            chmod($path, 0o600);
        }
    }

    public function test_store_from_upload_stores_image_type(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg');

        $meta = app(MaxAttachmentStore::class)->storeFromUpload($uploaded);

        $this->assertSame('image', $meta['type']);
        $this->assertSame('image/jpeg', $meta['mime']);
    }

    public function test_store_from_upload_stores_video_type(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4');

        $meta = app(MaxAttachmentStore::class)->storeFromUpload($uploaded);

        $this->assertSame('video', $meta['type']);
    }

    public function test_store_from_upload_stores_audio_type(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->create('voice.mp3', 10, 'audio/mpeg');

        $meta = app(MaxAttachmentStore::class)->storeFromUpload($uploaded);

        $this->assertSame('audio', $meta['type']);
    }

    public function test_store_from_upload_prefersgetClientOriginalExtension_over_mime(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->create('photo.jpg', 10, 'image/png');

        $meta = app(MaxAttachmentStore::class)->storeFromUpload($uploaded);

        $this->assertStringEndsWith('.jpg', $meta['path']);
    }

    public function test_store_from_upload_falls_back_to_bin_extension_for_unknown_mime(): void
    {
        Storage::fake('local');

        $uploaded = UploadedFile::fake()->createWithContent('data', (string) base64_decode('AAAA', true));

        $meta = app(MaxAttachmentStore::class)->storeFromUpload($uploaded);

        $this->assertSame('file', $meta['type']);
    }

    public function test_store_from_incoming_audio_attachment(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('audio-bytes', 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new AudioAttachment(new MediaAttachmentPayload(
                url: 'https://cdn.max.ru/voice.mp3',
                token: 'audio-token',
            )),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('audio', $meta['type']);
        $this->assertArrayHasKey('path', $meta);
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_store_from_incoming_file_attachment(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('file-bytes', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://cdn.max.ru/docs/data.csv', token: 'file-token')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('file', $meta['type']);
        $this->assertArrayHasKey('name', $meta);
        $this->assertSame('data.csv', $meta['name']);
    }

    public function test_file_filename_from_webhook_overrides_url_name_and_extension(): void
    {
        Storage::fake('local');
        Http::fake([
            'fd.oneme.ru/*' => Http::response('doc-bytes', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://fd.oneme.ru/getfile?rq=abc&expires=123', token: 'file-token'), filename: 'Отчёт за 2026 год.pdf'),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('path', $meta);
        $this->assertSame('Отчёт за 2026 год.pdf', $meta['name']);
        $this->assertTrue(str_ends_with($meta['path'], '.pdf'));
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_file_filename_is_sanitized_to_basename_without_path_components(): void
    {
        Storage::fake('local');
        // Closure-форма обязательна при нескольких скачиваниях на одном фейке:
        // Http::response() отдаёт один promise с одним stream, и sinkStubHandler
        // на втором запросе читает его без rewind — тело пустое, размер 0.
        Http::fake(fn () => Http::response('file-bytes', 200, ['Content-Type' => 'application/octet-stream']));

        $store = app(MaxAttachmentStore::class);
        $payload = new FileAttachmentPayload(url: 'https://cdn.max.ru/getfile?rq=abc', token: 'file-token');

        $withSlashes = $store->storeFromIncoming([
            new FileAttachment($payload, filename: '../../../etc/passwd'),
        ]);
        $withBackslashes = $store->storeFromIncoming([
            new FileAttachment($payload, filename: 'C:\\Users\\max\\report.docx'),
        ]);

        $slashed = $withSlashes[0];
        $backslashed = $withBackslashes[0];
        $this->assertArrayHasKey('name', $slashed);
        $this->assertArrayHasKey('name', $backslashed);
        $this->assertArrayHasKey('path', $backslashed);
        $this->assertSame('passwd', $slashed['name']);
        $this->assertSame('report.docx', $backslashed['name']);
        $this->assertTrue(str_ends_with($backslashed['path'], '.docx'));
    }

    public function test_file_filename_control_characters_are_stripped(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('file-bytes', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://cdn.max.ru/getfile?rq=abc', token: 'file-token'), filename: "report\r\n\x00final.pdf"),
        ]);

        $meta = $meta[0];
        $this->assertArrayHasKey('name', $meta);
        $this->assertSame('reportfinal.pdf', $meta['name']);
    }

    public function test_file_filename_falls_back_to_url_name_when_empty_or_dots_only(): void
    {
        Storage::fake('local');
        // Closure-форма — см. комментарий в тесте про санитизацию имени файла.
        Http::fake(fn () => Http::response('file-bytes', 200, ['Content-Type' => 'application/octet-stream']));

        $store = app(MaxAttachmentStore::class);
        $payload = new FileAttachmentPayload(url: 'https://cdn.max.ru/docs/report.pdf', token: 'file-token');

        $empty = $store->storeFromIncoming([
            new FileAttachment($payload, filename: ''),
        ]);
        $dots = $store->storeFromIncoming([
            new FileAttachment($payload, filename: '...'),
        ]);

        $emptyMeta = $empty[0];
        $dotsMeta = $dots[0];
        $this->assertArrayHasKey('name', $emptyMeta);
        $this->assertArrayHasKey('name', $dotsMeta);
        $this->assertSame('report.pdf', $emptyMeta['name']);
        $this->assertSame('report.pdf', $dotsMeta['name']);
    }

    public function test_file_extension_falls_back_to_url_when_filename_has_no_usable_extension(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('file-bytes', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://cdn.max.ru/docs/data.csv', token: 'file-token'), filename: 'README'),
        ]);

        $meta = $meta[0];
        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('path', $meta);
        $this->assertSame('README', $meta['name']);
        $this->assertTrue(str_ends_with($meta['path'], '.csv'));
    }

    public function test_store_from_incoming_sticker_downloads_image_and_keeps_code(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('webp-bytes', 200, ['Content-Type' => 'image/webp']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new StickerAttachment(new StickerAttachmentPayload(
                url: 'https://cdn.max.ru/stickers/s1.webp',
                code: 's1',
            )),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('sticker', $meta['type']);
        $this->assertSame('s1', $meta['code'] ?? null);
        $this->assertArrayHasKey('path', $meta);
        Storage::disk('local')->assertExists($meta['path']);
    }

    public function test_store_from_incoming_sticker_without_url_keeps_code_only(): void
    {
        Storage::fake('local');
        Http::fake();

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new StickerAttachment(new StickerAttachmentPayload(url: '', code: 's2')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('sticker', $meta['type']);
        $this->assertSame('s2', $meta['code'] ?? null);
        $this->assertArrayNotHasKey('path', $meta);
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_store_from_incoming_contact_uses_max_info_name_and_vcf_phone(): void
    {
        $vcf = "BEGIN:VCARD\nVERSION:3.0\nFN:Мария Сидорова\nTEL;TYPE=CELL:+79990001122\nEND:VCARD";

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ContactAttachment(new ContactAttachmentPayload(
                hash: 'abc',
                vcfInfo: $vcf,
                maxInfo: ['name' => 'Мария Сидорова'],
            )),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('contact', $meta['type']);
        $this->assertSame('Мария Сидорова', $meta['name'] ?? null);
        $this->assertSame('+79990001122', $meta['phone'] ?? null);
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_store_from_incoming_contact_falls_back_to_vcard_escaped_lines(): void
    {
        $vcf = 'BEGIN:VCARD\\nVERSION:3.0\\nFN:Пётр Иванов\\nTEL;TYPE=CELL:+79995556677\\nEND:VCARD';

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ContactAttachment(new ContactAttachmentPayload(
                hash: 'abc',
                vcfInfo: $vcf,
            )),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('contact', $meta['type']);
        $this->assertSame('Пётр Иванов', $meta['name'] ?? null);
        $this->assertSame('+79995556677', $meta['phone'] ?? null);
    }

    public function test_store_from_incoming_contact_builds_name_from_first_and_last_name(): void
    {
        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ContactAttachment(new ContactAttachmentPayload(
                hash: 'abc',
                maxInfo: ['first_name' => 'Иван', 'last_name' => 'Петров'],
            )),
        ]);

        $this->assertCount(1, $meta);
        $this->assertSame('Иван Петров', $meta[0]['name'] ?? null);
        $this->assertArrayNotHasKey('phone', $meta[0]);
    }

    public function test_store_from_incoming_contact_with_empty_payload_object_stores_type_only(): void
    {
        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ContactAttachment(new ContactAttachmentPayload(hash: 'abc')),
        ]);

        $this->assertSame([['type' => 'contact']], $meta);
    }

    public function test_store_from_incoming_contact_with_phone_only_vcard_has_no_name(): void
    {
        $vcf = "BEGIN:VCARD\nTEL;TYPE=CELL:+79995556677\nEND:VCARD";

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ContactAttachment(new ContactAttachmentPayload(
                hash: 'abc',
                vcfInfo: $vcf,
            )),
        ]);

        $this->assertCount(1, $meta);
        $this->assertArrayNotHasKey('name', $meta[0]);
        $this->assertSame('+79995556677', $meta[0]['phone'] ?? null);
    }

    public function test_store_from_incoming_location_uses_top_level_coordinates(): void
    {
        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new LocationAttachment(latitude: 55.7558, longitude: 37.6173),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('location', $meta['type']);
        $this->assertSame(55.7558, $meta['latitude'] ?? null);
        $this->assertSame(37.6173, $meta['longitude'] ?? null);
    }

    public function test_store_from_incoming_share_stores_link_metadata(): void
    {
        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ShareAttachment(
                new ShareAttachmentPayload(url: 'https://example.com/article', token: 'share-token'),
                title: 'Заголовок',
                description: 'Описание',
                imageUrl: 'https://example.com/cover.png',
            ),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('share', $meta['type']);
        $this->assertSame('https://example.com/article', $meta['url'] ?? null);
        $this->assertSame('Заголовок', $meta['title'] ?? null);
        $this->assertSame('Описание', $meta['description'] ?? null);
        $this->assertArrayNotHasKey('path', $meta);
        $this->assertArrayNotHasKey('image_url', $meta);
    }

    public function test_store_from_incoming_share_drops_invalid_urls(): void
    {
        foreach ([123, '', 'javascript:alert(1)', '/relative'] as $invalid) {
            $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
                new ShareAttachment(new ShareAttachmentPayload(url: is_string($invalid) ? $invalid : null)),
            ]);

            $this->assertCount(1, $meta);
            $this->assertSame('share', $meta[0]['type']);
            $this->assertArrayNotHasKey('url', $meta[0]);
        }
    }

    public function test_store_from_incoming_share_truncates_oversized_and_ignores_non_string_fields(): void
    {
        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new ShareAttachment(
                new ShareAttachmentPayload(url: 'https://example.com'),
                description: str_repeat('d', 5000),
            ),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayNotHasKey('title', $meta);
        $this->assertSame(2000, mb_strlen($meta['description'] ?? ''));
    }

    public function test_store_from_incoming_inline_keyboard_stores_button_texts(): void
    {
        $keyboard = new Keyboard([
            [new CallbackButton('Да', 'yes'), new CallbackButton('Нет', 'no')],
            [new LinkButton('Сайт', 'https://example.com')],
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new InlineKeyboardAttachment($keyboard),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('inline_keyboard', $meta['type']);
        $this->assertSame(['Да', 'Нет', 'Сайт'], $meta['buttons'] ?? null);
    }

    public function test_store_from_incoming_inline_keyboard_without_buttons_is_skipped(): void
    {
        $this->assertSame([], app(MaxAttachmentStore::class)->storeFromIncoming([
            new InlineKeyboardAttachment(new Keyboard([])),
        ]));
    }

    public function test_store_from_incoming_video_stores_metadata_without_download(): void
    {
        Storage::fake('local');
        Http::fake();

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new VideoAttachment(new MediaAttachmentPayload(
                url: 'https://cdn.max.ru/clip.mp4',
                token: 'video-token',
            )),
        ]);

        $this->assertCount(1, $meta);
        $this->assertSame('video', $meta[0]['type']);
        $this->assertArrayNotHasKey('path', $meta[0]);
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_download_rejects_non_http_url(): void
    {
        Storage::fake('local');
        Http::fake();

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'file:///etc/passwd')),
        ]);

        $this->assertSame([['type' => 'image']], $meta);
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_download_returns_type_only_on_null_url(): void
    {
        Storage::fake('local');

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: '')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('image', $meta['type']);
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_download_returns_type_only_on_exception(): void
    {
        Storage::fake('local');
        Http::fake(fn () => throw new \RuntimeException('network error'));

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://cdn.max.ru/file.bin', token: 'file-token')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertSame('file', $meta['type']);
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_extension_from_url_query_params(): void
    {
        Storage::fake('local');
        Http::fake([
            'fd.oneme.ru/*' => Http::response('content', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new FileAttachment(new FileAttachmentPayload(url: 'https://fd.oneme.ru/getfile?filename=report.pdf', token: 'file-token')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayHasKey('path', $meta);
    }

    public function test_extension_fallback_to_type_when_no_url_or_mime_extension(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('bytes', 200, ['Content-Type' => 'application/x-mystery']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'https://cdn.max.ru/noext')),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertArrayHasKey('path', $meta);
        $this->assertStringEndsWith('.jpg', $meta['path']);
    }

    public function test_audio_extension_falls_back_to_mp3_when_no_mime_extension(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('bytes', 200, ['Content-Type' => 'application/x-mystery']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new AudioAttachment(new MediaAttachmentPayload(
                url: 'https://cdn.max.ru/noext',
                token: 'audio-token',
            )),
        ]);

        $this->assertCount(1, $meta);
        $meta = $meta[0];
        $this->assertStringEndsWith('.mp3', $meta['path'] ?? '');
    }

    public function test_store_from_incoming_skips_non_attachment_items(): void
    {
        $result = app(MaxAttachmentStore::class)->storeFromIncoming([
            'not-an-attachment',
            null,
        ]);

        $this->assertSame([], $result);
    }

    public function test_store_from_incoming_keeps_all_media_attachments(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/a.png' => Http::response('first-bytes', 200, ['Content-Type' => 'image/png']),
            'cdn.max.ru/b.png' => Http::response('second-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $result = app(MaxAttachmentStore::class)->storeFromIncoming([
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'https://cdn.max.ru/a.png')),
            new PhotoAttachment(new PhotoAttachmentPayload(photoId: 1, token: 'photo-token', url: 'https://cdn.max.ru/b.png')),
        ]);

        $this->assertCount(2, $result);
        $this->assertSame('image', $result[0]['type']);
        $this->assertSame('image', $result[1]['type']);
        Storage::disk('local')->assertExists($result[0]['path'] ?? '');
        Storage::disk('local')->assertExists($result[1]['path'] ?? '');
    }

    public function test_delete_stored_removes_only_paths_inside_attachments_directory(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('chat-attachments/a.png', 'bytes');
        $disk->put('secret/b.png', 'bytes');

        app(MaxAttachmentStore::class)->deleteStored([
            ['type' => 'image', 'path' => 'chat-attachments/a.png'],
            ['type' => 'image', 'path' => 'secret/b.png'],
            ['type' => 'image', 'path' => '../../secret/c.png'],
            ['type' => 'image'],
            'not-an-array',
        ]);

        $disk->assertMissing('chat-attachments/a.png');
        $disk->assertExists('secret/b.png');
    }

    public function test_delete_stored_accepts_null_attachment(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('chat-attachments/a.png', 'bytes');

        app(MaxAttachmentStore::class)->deleteStored(null);

        Storage::disk('local')->assertExists('chat-attachments/a.png');
    }
}
