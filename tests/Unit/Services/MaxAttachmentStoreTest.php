<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Services;

use GeekCo\FilamentMaxChat\Services\MaxAttachmentStore;
use GeekCo\FilamentMaxChat\Tests\TestCase;
use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\FileAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\ImageAttachmentPayload;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: 'https://cdn.max.ru/photo.png'),
            ),
        ]);

        $this->assertIsArray($meta);
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
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: 'https://cdn.max.ru/missing.jpg'),
            ),
        ]);

        $this->assertIsArray($meta);
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
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: 'https://cdn.max.ru/big.jpg'),
            ),
        ]);

        $this->assertIsArray($meta);
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_extension_is_taken_from_url_when_content_type_is_generic(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('doc-bytes', 200, ['Content-Type' => 'application/octet-stream']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new Attachment(
                type: AttachmentType::File,
                payload: new FileAttachmentPayload(url: 'https://cdn.max.ru/media/b2f/report.pdf'),
            ),
        ]);

        $this->assertIsArray($meta);
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
            new Attachment(
                type: AttachmentType::File,
                payload: new FileAttachmentPayload(url: 'https://fd.oneme.ru/getfile?rq=abc&expires=123'),
            ),
        ]);

        $this->assertIsArray($meta);
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
            new Attachment(type: AttachmentType::Video, payload: []),
        ]);

        $this->assertIsArray($meta);
        $this->assertSame('video', $meta['type']);
        $this->assertArrayNotHasKey('path', $meta);
        $this->assertSame([], Http::recorded()->all());
    }

    public function test_no_media_attachments_returns_null(): void
    {
        $this->assertNull(app(MaxAttachmentStore::class)->storeFromIncoming([
            new Attachment(type: AttachmentType::InlineKeyboard, payload: []),
        ]));
        $this->assertNull(app(MaxAttachmentStore::class)->storeFromIncoming(null));
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
            new Attachment(
                type: AttachmentType::Audio,
                payload: new \GeekCo\MaxPhpClient\Dto\AudioAttachmentPayload(url: 'https://cdn.max.ru/voice.mp3'),
            ),
        ]);

        $this->assertIsArray($meta);
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
            new Attachment(
                type: AttachmentType::File,
                payload: new FileAttachmentPayload(url: 'https://cdn.max.ru/docs/data.csv'),
            ),
        ]);

        $this->assertIsArray($meta);
        $this->assertSame('file', $meta['type']);
        $this->assertArrayHasKey('name', $meta);
        $this->assertSame('data.csv', $meta['name']);
    }

    public function test_download_returns_type_only_on_null_url(): void
    {
        Storage::fake('local');

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: ''),
            ),
        ]);

        $this->assertIsArray($meta);
        $this->assertSame('image', $meta['type']);
        $this->assertArrayNotHasKey('path', $meta);
    }

    public function test_download_returns_type_only_on_exception(): void
    {
        Storage::fake('local');
        Http::fake(fn () => throw new \RuntimeException('network error'));

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new Attachment(
                type: AttachmentType::File,
                payload: new FileAttachmentPayload(url: 'https://cdn.max.ru/file.bin'),
            ),
        ]);

        $this->assertIsArray($meta);
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
            new Attachment(
                type: AttachmentType::File,
                payload: new FileAttachmentPayload(url: 'https://fd.oneme.ru/getfile?filename=report.pdf'),
            ),
        ]);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('path', $meta);
    }

    public function test_extension_fallback_to_type_when_no_url_or_mime_extension(): void
    {
        Storage::fake('local');
        Http::fake([
            'cdn.max.ru/*' => Http::response('bytes', 200, ['Content-Type' => 'application/x-mystery']),
        ]);

        $meta = app(MaxAttachmentStore::class)->storeFromIncoming([
            new Attachment(
                type: AttachmentType::Image,
                payload: new ImageAttachmentPayload(url: 'https://cdn.max.ru/noext'),
            ),
        ]);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('path', $meta);
        $this->assertStringEndsWith('.jpg', $meta['path']);
    }

    public function test_store_from_incoming_skips_non_attachment_items(): void
    {
        $result = app(MaxAttachmentStore::class)->storeFromIncoming([
            'not-an-attachment',
            null,
        ]);

        $this->assertNull($result);
    }
}
