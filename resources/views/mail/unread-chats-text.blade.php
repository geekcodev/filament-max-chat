{{-- Текстовая часть письма уходит как text/plain: экранирование здесь только
     исказило бы сообщения (кавычки превратились бы в &#039;), разметки нет. --}}
{!! __('filament-max-chat::chat.mail.heading') !!}

{!! __('filament-max-chat::chat.mail.intro') !!}

@foreach ($chats as $chat)
{!! $chat['name'] !!}
{!! __('filament-max-chat::chat.mail.unread_count', ['count' => $chat['count']]) !!}
{!! $chat['preview'] !!}
{!! $chat['url'] !!}

@endforeach
{!! __('filament-max-chat::chat.mail.footer') !!}
