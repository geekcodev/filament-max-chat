<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Chat',
    ],

    'direction' => [
        'in' => 'Incoming',
        'out' => 'Outgoing',
    ],

    'sender' => [
        'user' => 'User',
        'operator' => 'Operator',
        'bot' => 'Bot',
    ],

    'preview' => [
        'image' => '📷 Photo',
        'video' => '🎬 Video',
        'audio' => '🎵 Audio',
        'file' => '📄 File',
        'image_unavailable' => '📷 Photo unavailable',
        'file_unavailable' => '📎 File unavailable',
    ],

    'fallback_user' => 'User :id',

    'empty_conversations' => 'No active conversations',
    'select_conversation' => 'Select a conversation on the left',
    'empty_messages' => 'No messages yet',
    'load_older' => '↑ Load older messages',
    'loading' => 'Loading…',
    'clear_history' => 'Clear history',
    'clear_confirm' => 'Delete all messages in this chat? This cannot be undone.',
    'remove_chat' => 'Remove chat',
    'remove_chat_confirm' => 'Remove this conversation from the list? Message history will be kept.',
    'delete_message' => 'Delete message',
    'delete_confirm' => 'Delete this message?',

    'formatting' => 'Formatting',
    'format_bold' => 'B',
    'format_italic' => 'I',
    'format_underline' => 'U',
    'format_strikethrough' => 'S',
    'format_code' => '</>',
    'insert_link' => 'Insert link',
    'insert_link_prompt' => 'Insert link (URL):',
    'preview_toggle' => 'Preview',
    'source_toggle' => 'Source',
    'placeholder' => 'Message… Format with the buttons above or HTML tags.',
    'uploading' => 'Uploading files…',
    'remove_file' => 'Remove file',
    'send' => 'Send',
    'send_failed' => 'Failed to send the message. Please try again.',
    'no_answer_permission' => 'You are not allowed to reply in this chat',

    'notification_title' => 'New message',
    'notification_body' => 'You have a new message in chat',
    'notification_sound_on' => 'Sound notifications: on',
    'notification_sound_off' => 'Sound notifications: off',

    'close' => 'Close',
];
