<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\AudioAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\ContactAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\FileAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\ImageAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\InlineKeyboardAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\LocationAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\VideoAttachmentPayload;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use GeekCo\MaxPhpClient\Security\ContactPhoneExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores chat attachments on a private disk and returns metadata
 * for the max_chat_messages.attachment column.
 *
 * Метаданные вложения — один массив на вложение: общие ключи (`type` и
 * скачанные `path`/`name`/`mime`/`size`/`url`) плюс специфичные для типа
 * (`code` у стикера, `latitude`/`longitude` у геолокации, `title`/`description`
 * у превью ссылки, `name`/`phone` у контакта, `buttons` у inline-клавиатуры).
 * Вложения без файла (контакт, геолокация, ссылка) файла не имеют, поэтому
 * отдаются из метаданных, а не через роут.
 *
 * @phpstan-type StoredAttachment array{type: string, path?: string, name?: string, mime?: string, size?: int, url?: string, code?: string, latitude?: float, longitude?: float, title?: string, description?: string, phone?: string, buttons?: list<string>}
 */
class MaxAttachmentStore
{
    public const int DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

    private const int MAX_NAME_LENGTH = 255;

    private const int MAX_PHONE_LENGTH = 64;

    private const int MAX_TITLE_LENGTH = 512;

    private const int MAX_DESCRIPTION_LENGTH = 2000;

    private const int MAX_BUTTON_LENGTH = 128;

    /**
     * Extracts all attachments from a MAX update and stores them locally. Медиа
     * (изображение, видео, аудио, файл, стикер) скачивается на приватный диск,
     * остальные типы (контакт, геолокация, превью ссылки, inline-клавиатура)
     * сохраняются как есть метаданными. Неизвестное вложение пропускается.
     *
     * @param list<mixed>|null $attachments
     *
     * @return list<StoredAttachment>
     */
    public function storeFromIncoming(?array $attachments): array
    {
        $maxBytes = $this->maxBytes();

        $stored = [];

        foreach ($attachments ?? [] as $attachment) {
            if (!$attachment instanceof Attachment) {
                continue;
            }

            $meta = $this->metadataFor($attachment, $maxBytes);

            if ($meta !== null) {
                $stored[] = $meta;
            }
        }

        return $stored;
    }

    /**
     * Метаданные одного вложения. `match` перечисляет все типы `AttachmentType`
     * без `default`: добавление нового типа в ядро снова упрётся в анализатор,
     * а не молча потеряется, как раньше у стикера, контакта, геолокации и ссылки.
     *
     * @return StoredAttachment|null
     */
    private function metadataFor(Attachment $attachment, int $maxBytes): ?array
    {
        return match ($attachment->type) {
            AttachmentType::Image => $attachment->payload instanceof ImageAttachmentPayload
                ? $this->download($attachment->payload->url, 'image', 'image', $maxBytes)
                : ['type' => 'image'],
            AttachmentType::Video => $attachment->payload instanceof VideoAttachmentPayload
                ? $this->download($attachment->payload->url?->mp4, 'video', 'video', $maxBytes)
                : ['type' => 'video'],
            AttachmentType::Audio => $attachment->payload instanceof AudioAttachmentPayload
                ? $this->download($attachment->payload->url, 'audio', 'audio', $maxBytes)
                : ['type' => 'audio'],
            AttachmentType::File => $attachment->payload instanceof FileAttachmentPayload
                ? $this->download($attachment->payload->url, 'file', 'file', $maxBytes)
                : ['type' => 'file'],
            AttachmentType::Sticker => $this->stickerMetadata($attachment->payload, $maxBytes),
            AttachmentType::Contact => $this->contactMetadata($attachment->payload),
            AttachmentType::Location => $this->locationMetadata($attachment),
            AttachmentType::Share => $this->shareMetadata($attachment->payload),
            AttachmentType::InlineKeyboard => $this->keyboardMetadata($attachment->payload),
        };
    }

    /**
     * Стикер — картинка (обычно webp) по `payload.url`; код стикера сохраняется,
     * чтобы его можно было показать или сопоставить. Скачивание идёт общим
     * путём, поэтому лимит размера и приватный диск у него те же, что у медиа.
     *
     * @return StoredAttachment
     */
    private function stickerMetadata(mixed $payload, int $maxBytes): array
    {
        if (! is_array($payload)) {
            return ['type' => 'sticker'];
        }

        $meta = $this->download($this->stringValue($payload['url'] ?? null), 'sticker', 'sticker', $maxBytes);
        $code = $this->stringValue($payload['code'] ?? null);

        if ($code !== null) {
            $meta['code'] = $this->limit($code, self::MAX_NAME_LENGTH);
        }

        return $meta;
    }

    /**
     * Контакт по кнопке `request_contact`: имени и телефона достаточно для
     * карточки оператора. Имя берётся из `max_info` (профиль MAX), иначе из
     * vCard (`FN`); телефон — из `vcf_phone`, а во входящем payload его нет,
     * поэтому номер разбирается из `vcf_info` ядром.
     *
     * @return StoredAttachment
     */
    private function contactMetadata(mixed $payload): array
    {
        if (! $payload instanceof ContactAttachmentPayload) {
            return ['type' => 'contact'];
        }

        $meta = ['type' => 'contact'];

        $name = $this->contactName($payload);

        if ($name !== null) {
            $meta['name'] = $name;
        }

        $phone = $payload->vcfPhone ?? ContactPhoneExtractor::fromVcf((string) $payload->vcfInfo);
        $phone = is_string($phone) ? $this->limit(trim($phone), self::MAX_PHONE_LENGTH) : '';

        if ($phone !== '') {
            $meta['phone'] = $phone;
        }

        return $meta;
    }

    private function contactName(ContactAttachmentPayload $payload): ?string
    {
        $info = $payload->maxInfo;

        if (is_array($info)) {
            $name = $this->stringValue($info['name'] ?? null);

            if ($name !== null) {
                return $this->limit($name, self::MAX_NAME_LENGTH);
            }

            $combined = trim(($this->stringValue($info['first_name'] ?? null) ?? '')
                . ' ' . ($this->stringValue($info['last_name'] ?? null) ?? ''));

            if ($combined !== '') {
                return $this->limit($combined, self::MAX_NAME_LENGTH);
            }
        }

        return $this->nameFromVcf($payload->vcfInfo);
    }

    /**
     * Имя из vCard (`FN`). Строка разбивается и по реальным переводам строк, и по
     * их JSON-экранированным литералам (`\r\n`, `\n`, `\r`) — так же поступает
     * `ContactPhoneExtractor` ядра: в транспорте встречаются обе формы.
     */
    private function nameFromVcf(?string $vcf): ?string
    {
        if ($vcf === null || $vcf === '') {
            return null;
        }

        $lines = preg_split('/\\\\r\\\\n|\\\\n|\\\\r|\r\n|\n|\r/', $vcf) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^FN(?:;[^:]*)?:(.+)$/i', trim($line), $matches) === 1) {
                $name = trim($matches[1]);

                if ($name !== '') {
                    return $this->limit($name, self::MAX_NAME_LENGTH);
                }
            }
        }

        return null;
    }

    /**
     * Геолокация: координаты приходят на верхнем уровне вложения (так в спеке),
     * реже — внутри `payload`. Файла нет, поэтому отдаются сами координаты.
     *
     * @return StoredAttachment
     */
    private function locationMetadata(Attachment $attachment): array
    {
        $latitude = $attachment->latitude;
        $longitude = $attachment->longitude;

        if ($attachment->payload instanceof LocationAttachmentPayload) {
            $latitude ??= $attachment->payload->latitude;
            $longitude ??= $attachment->payload->longitude;
        }

        if ($latitude === null || $longitude === null) {
            return ['type' => 'location'];
        }

        return ['type' => 'location', 'latitude' => $latitude, 'longitude' => $longitude];
    }

    /**
     * Превью внешней ссылки. Картинку превью (`image_url`) не скачиваем: это
     * произвольный внешний домен (не CDN MAX), а скачивание чужих URL — путь к
     * SSRF. Показываем заголовок, описание и саму ссылку.
     *
     * @return StoredAttachment
     */
    private function shareMetadata(mixed $payload): array
    {
        $meta = ['type' => 'share'];

        if (! is_array($payload)) {
            return $meta;
        }

        $url = self::httpUrl($payload['url'] ?? null);

        if ($url !== null) {
            $meta['url'] = $url;
        }

        $title = $this->stringValue($payload['title'] ?? null);

        if ($title !== null) {
            $meta['title'] = $this->limit($title, self::MAX_TITLE_LENGTH);
        }

        $description = $this->stringValue($payload['description'] ?? null);

        if ($description !== null) {
            $meta['description'] = $this->limit($description, self::MAX_DESCRIPTION_LENGTH);
        }

        return $meta;
    }

    /**
     * Inline-клавиатура сообщения: для оператора храним только подписи кнопок —
     * нажимать их он не может, но по ним видно, что предлагалось пользователю.
     * Клавиатура без кнопок не сохраняется, чтобы не заводить пустое вложение.
     *
     * @return StoredAttachment|null
     */
    private function keyboardMetadata(mixed $payload): ?array
    {
        if (! $payload instanceof InlineKeyboardAttachmentPayload) {
            return null;
        }

        $buttons = [];

        foreach ($payload->rows as $row) {
            foreach ($row->buttons as $button) {
                $text = $this->stringValue($button->text);

                if ($text !== null) {
                    $buttons[] = $this->limit($text, self::MAX_BUTTON_LENGTH);
                }
            }
        }

        if ($buttons === []) {
            return null;
        }

        return ['type' => 'inline_keyboard', 'buttons' => $buttons];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Проверка URL перед скачиванием и перед показом ссылкой: пропускаем только
     * http/https, чтобы вложение не увело запрос на другой протокол (`file:`,
     * `data:`) и чтобы `href` превью не оказался `javascript:`. Публичный метод,
     * потому что тот же фильтр нужен представлению: метаданные в базе могли быть
     * записаны в обход `storeFromIncoming()`.
     */
    public static function httpUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return $value;
    }

    private function limit(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * Stores a file uploaded by an operator via Livewire.
     *
     * @return array{type: string, path: string, name: string, mime: string, size: int}
     */
    public function storeFromUpload(UploadedFile $file): array
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $extension = $this->extensionFor($mime, $file->getClientOriginalExtension());
        $path = sprintf('%s/%s.%s', $this->directory(), Str::uuid()->toString(), $extension);

        $this->putStream((string) $file->getRealPath(), $path);

        return [
            'type' => $this->typeForMime($mime),
            'path' => $path,
            'name' => $file->getClientOriginalName() ?: ('file.' . $extension),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ];
    }

    /**
     * Deletes files of a message that no longer exists in MAX, so a private disk
     * keeps no orphaned attachments. Paths outside the configured directory are
     * ignored: the attachment column is data, not a trusted instruction.
     *
     * @param list<mixed>|null $attachment
     */
    public function deleteStored(?array $attachment): void
    {
        $directory = $this->directory();

        foreach ($attachment ?? [] as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $path = $meta['path'] ?? null;

            if (! is_string($path) || ! str_starts_with($path, $directory.'/')) {
                continue;
            }

            $this->disk()->delete($path);
        }
    }

    private function maxBytes(): int
    {
        return config()->integer('filament-max-chat.attachments.max_bytes', self::DEFAULT_MAX_BYTES);
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config()->string('filament-max-chat.attachments.disk', 'local'));
    }

    private function directory(): string
    {
        return rtrim(config()->string('filament-max-chat.attachments.directory', 'chat-attachments'), '/');
    }

    /**
     * @return StoredAttachment
     */
    private function download(?string $url, string $type, string $fallbackName, int $maxBytes): array
    {
        if ($url === null || self::httpUrl($url) === null) {
            return ['type' => $type];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'chat-att-');

        try {
            $response = Http::timeout(30)->connectTimeout(10)->sink($temporary)->get($url);

            if (!$response->successful()) {
                Log::warning('Chat attachment download failed', ['url' => $url, 'status' => $response->status()]);

                return ['type' => $type];
            }

            $size = (int) filesize($temporary);

            if ($size === 0 || $size > $maxBytes) {
                Log::warning('Chat attachment rejected by size limit', ['url' => $url, 'size' => $size]);

                return ['type' => $type];
            }

            $cdnMime = strtok((string) ($response->header('Content-Type') ?: ''), ';') ?: 'application/octet-stream';
            $sniffed = @mime_content_type($temporary);
            $mime = is_string($sniffed) && $sniffed !== '' && $cdnMime === 'application/octet-stream'
                ? $sniffed
                : $cdnMime;

            $extension = $this->extensionFor($mime, $this->extensionFromUrl((string) $url));
            if ($extension === '') {
                $extension = $this->extensionForType($type);
            }
            $path = sprintf('%s/%s.%s', $this->directory(), Str::uuid()->toString(), $extension);

            Log::info('Chat attachment stored', [
                'url' => $url,
                'cdn_mime' => $cdnMime,
                'mime' => $mime,
                'extension' => $extension,
                'size' => $size,
            ]);

            $this->putStream($temporary, $path);
        } catch (\Throwable $exception) {
            Log::warning('Chat attachment download error', ['url' => $url, 'error' => $exception->getMessage()]);

            return ['type' => $type];
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return [
            'type' => $type,
            'path' => $path,
            'name' => $this->nameFromUrl((string) $url) ?? ($fallbackName . '.' . $extension),
            'mime' => $mime,
            'size' => $size,
            'url' => $url,
        ];
    }

    private function putStream(string $source, string $destinationPath): void
    {
        $stream = fopen($source, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open file for reading: '.$source);
        }

        try {
            $this->disk()->put($destinationPath, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function extensionFor(string $mime, string $preferred = ''): string
    {
        if ($preferred !== '' && preg_match('/^[a-z0-9]{1,8}$/i', $preferred) === 1) {
            return strtolower($preferred);
        }

        return MimeTypes::getDefault()->getExtensions($mime)[0] ?? '';
    }

    private function extensionForType(string $type): string
    {
        return match ($type) {
            'image' => 'jpg',
            'sticker' => 'png',
            'video' => 'mp4',
            'audio' => 'mp3',
            default => 'bin',
        };
    }

    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = is_string($path) ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        if (preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1) {
            return $extension;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (is_string($query)) {
            parse_str($query, $params);
            foreach (['filename', 'file_name', 'name'] as $key) {
                $candidate = is_string($params[$key] ?? null) ? strtolower(pathinfo((string) $params[$key], PATHINFO_EXTENSION)) : '';
                if (preg_match('/^[a-z0-9]{1,8}$/', $candidate) === 1) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function nameFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        if (preg_match('/^[A-Za-z0-9._ ()-]{1,120}$/', $name) !== 1 || ! str_contains($name, '.')) {
            return null;
        }

        return $name;
    }

    private function typeForMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }
}
