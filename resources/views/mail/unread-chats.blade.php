<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('filament-max-chat::chat.mail.subject') }}</title>
</head>
<body style="margin: 0; padding: 16px; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #111827;">
<div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
    <h1 style="margin: 0 0 12px; font-size: 18px;">{{ __('filament-max-chat::chat.mail.heading') }}</h1>
    <p style="margin: 0 0 16px; color: #6b7280;">{{ __('filament-max-chat::chat.mail.intro') }}</p>

    @foreach ($chats as $chat)
        <div style="border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; margin-bottom: 12px;">
            <p style="margin: 0 0 4px; font-weight: bold;">{{ $chat['name'] }}</p>
            <p style="margin: 0 0 4px; color: #6b7280;">{{ __('filament-max-chat::chat.mail.unread_count', ['count' => $chat['count']]) }}</p>
            <p style="margin: 0 0 8px;">{{ $chat['preview'] }}</p>
            <p style="margin: 0;"><a href="{{ $chat['url'] }}">{{ __('filament-max-chat::chat.mail.open') }}</a></p>
        </div>
    @endforeach

    <p style="margin: 16px 0 0; color: #9ca3af; font-size: 12px;">{{ __('filament-max-chat::chat.mail.footer') }}</p>
</div>
</body>
</html>
