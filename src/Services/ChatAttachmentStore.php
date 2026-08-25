<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Services;

use GeekCo\MaxPhpClient\Dto\Attachment;
use GeekCo\MaxPhpClient\Dto\AudioAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\FileAttachmentPayload;
use GeekCo\MaxPhpClient\Dto\ImageAttachmentPayload;
use GeekCo\MaxPhpClient\Enum\AttachmentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Stores chat attachments on a private disk and returns metadata
 * for the chat_messages.attachment column.
 */
class ChatAttachmentStore
{
    public const int DEFAULT_MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Extracts the first media attachment from an MAX update and stores it locally.
     *
     * @param list<mixed>|null $attachments
     * @return array{type: string, path?: string, name?: string, mime?: string, size?: int}|null
     */
    public function storeFromIncoming(?array $attachments): ?array
    {
        $maxBytes = $this->maxBytes();

        foreach ($attachments ?? [] as $attachment) {
            if (!$attachment instanceof Attachment) {
                continue;
            }

            $meta = match ($attachment->type) {
                AttachmentType::Image => $attachment->payload instanceof ImageAttachmentPayload
                    ? $this->download($attachment->payload->url, 'image', 'image', $maxBytes)
                    : null,
                AttachmentType::Audio => $attachment->payload instanceof AudioAttachmentPayload
                    ? $this->download($attachment->payload->url, 'audio', 'audio', $maxBytes)
                    : null,
                AttachmentType::File => $attachment->payload instanceof FileAttachmentPayload
                    ? $this->download($attachment->payload->url, 'file', 'file', $maxBytes)
                    : null,
                AttachmentType::Video => ['type' => 'video'],
                default => null,
            };

            if ($meta !== null) {
                return $meta;
            }
        }

        return null;
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
     * @return array{type: string, path?: string, name?: string, mime?: string, size?: int}
     */
    private function download(?string $url, string $type, string $fallbackName, int $maxBytes): array
    {
        if ($url === null || $url === '') {
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
