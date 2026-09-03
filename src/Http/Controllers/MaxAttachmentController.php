<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Http\Controllers;

use GeekCo\FilamentMaxChat\Models\MaxMessage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves chat attachments from private storage (only for staff with view permission).
 */
class MaxAttachmentController
{
    public function __invoke(Request $request, MaxMessage $message, int $index): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest((string) config()->string('filament-max-chat.route.login_url', '/admin/login'));
        }

        abort_unless($user->can((string) config()->string('filament-max-chat.permissions.view')) === true, 403);

        $disk = Storage::disk(config()->string('filament-max-chat.attachments.disk', 'local'));
        $directory = rtrim(config()->string('filament-max-chat.attachments.directory', 'chat-attachments'), '/');

        $meta = $message->attachmentAt($index) ?? [];
        $path = $meta['path'] ?? null;

        if (! is_string($path) || ! str_starts_with($path, $directory.'/')) {
            abort(404);
        }

        /** @var Filesystem $disk */
        if (! $disk->exists($path)) {
            abort(404);
        }

        $name = is_string($meta['name'] ?? null) ? $meta['name'] : basename($path);

        return $disk->response($path, $name);
    }
}
