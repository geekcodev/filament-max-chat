<?php

declare(strict_types=1);

namespace App\Livewire;

use GeekCo\FilamentMaxChat\Livewire\OperatorChat as BaseOperatorChat;

class CustomOperatorChat extends BaseOperatorChat
{
    public function createLead(): void
    {
        $chat = $this->activeConversation;
        $chatUser = $chat?->interlocutor();

        // Кастомная логика создания лида
        // ...
        
        $this->dispatch('lead-created');
    }

    public function requestContactManually(): void
    {
        // Можно вызвать системный метод или реализовать свою логику
        $this->requestContact();
    }
}
