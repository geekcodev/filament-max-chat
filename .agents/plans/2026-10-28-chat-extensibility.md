# План: Расширяемость и наследовательность плагина filament-max-chat

Дата: 2026-10-28  
Статус: Выполнен  
Дата завершения: 2026-10-28

## Цель

Сделать плагин расширяемым без копирования Livewire-компонента и Blade-шаблона, чтобы в хост-приложении можно было:

- Добавить кнопки "Запросить контакт" и "Создать заявку/лид" в шапке чата и возле поля ввода
- Передавать в эти действия данные: `user_id`, `chat_id`, имя, телефон из контекста чата
- Настраивать переходы на произвольные роуты/ресурсы Filament из хост-приложения
- Контролировать видимость кнопок через права доступа

## Контекст

- В `MaxUser` есть поля `phone`, `email`, `first_name`, `last_name` и др. (из laravel-max-client)
- В ядре/адаптере есть функционал записи телефона при шаринге подписанного контакта пользователем
- Нужна возможность добавления кнопок как в шапке чата, так и рядом с кнопками форматирования
- Роуты должны приниматься извне (на уровне хост-приложения)
- Можно добавить право `chat.manage`

## Текущее состояние

- Шаблон: `resources/views/components/operator-chat.blade.php`
- Livewire: `src/Livewire/OperatorChat.php`
- Конфиг: `config/filament-max-chat.php`
- Доступны в шаблоне: `$activeChat` (MaxChat), `$chatUser` (MaxUser через `interlocutor()`), `$activeChatId`,
  `$this->canAnswer`

## План реализации

### Шаг 1. Добавить Blade-стеки в шаблон для расширяемости

**Файл:** `resources/views/components/operator-chat.blade.php`

Места для добавления стеков:

1. **В шапке чата** (около строк 145-167, в блоке `@if ($this->canAnswer)`)
    - Добавить `@stack('filament-max-chat.header-actions-start')` перед существующими кнопками
    - Добавить `@stack('filament-max-chat.header-actions')` после существующих кнопок
    - Или просто `@stack('filament-max-chat.header-actions')` в том же flex-контейнере

2. **Рядом с кнопками форматирования** (около строк 319-368)
    - Добавить `@stack('filament-max-chat.formatting-actions-start')` перед кнопками форматирования
    - Добавить `@stack('filament-max-chat.formatting-actions')` после кнопок форматирования (перед превью)

3. **В футере формы ответа** (около строки 316)
    - Добавить `@stack('filament-max-chat.reply-footer')` после формы

4. **В информации о пользователе в модалке** (опционально, около строки 100-143)
    - Может быть полезно для показа доп.информации

**Конкретные правки:**

В шапке чата (строка ~146):

```blade
@if ($this->canAnswer)
    <div class="flex shrink-0 items-center gap-1">
        @stack('filament-max-chat.header-actions-start')
        <!-- существующие кнопки -->
        ...
        @stack('filament-max-chat.header-actions')
    </div>
@endif
```

Рядом с форматированием (строка ~319):

```blade
<div class="flex flex-wrap items-center gap-1">
    @stack('filament-max-chat.formatting-actions-start')
    <!-- существующие кнопки форматирования -->
    ...
    <button ... class="ml-auto ..."> <!-- превью кнопка -->
    ...
    </button>
    @stack('filament-max-chat.formatting-actions')
</div>
```

### Шаг 2. Расширить конфиг для декларативного задания действий

**Файл:** `config/filament-max-chat.php`

Добавить секцию `actions`:

```php
/*
|--------------------------------------------------------------------------
| Кастомные действия в интерфейсе чата
|--------------------------------------------------------------------------
|
| Позволяют добавить кнопки в шапку и возле поля ввода из конфига.
| Роуты передаются извне хост-приложением.
| Доступны переменные: $chat (MaxChat), $chatUser (MaxUser|null), $activeChatId
|
*/
'actions' => [
    'header' => [
        // Пример:
        // [
        //     'label' => 'Создать заявку',
        //     'icon' => 'heroicon-o-document-plus',
        //     'url' => function ($chat, $chatUser) {
        //         return route('filament.admin.resources.leads.create', [
        //             'chat_id' => $chat->chat_id,
        //             'user_id' => $chatUser?->user_id,
        //             'name' => trim(($chatUser?->first_name ?? '').' '.($chatUser?->last_name ?? '')),
        //             'phone' => $chatUser?->phone,
        //         ]);
        //     },
        //     'visible' => function ($chat, $chatUser) {
        //         return $chatUser !== null && auth()->user()?->can('leads.create');
        //     },
        //     'permission' => 'chat.manage', // опционально
        //     'open_new_tab' => false,
        // ],
    ],
    'formatting' => [
        // Кнопки рядом с кнопками форматирования
    ],
],
```

### Шаг 3. Добавить методы в Livewire компонент для рендеринга действий

**Файл:** `src/Livewire/OperatorChat.php`

Добавить computed свойства:

```php
#[Computed]
public function headerActions(): array
{
    return $this->resolveActions('header');
}

#[Computed]
public function formattingActions(): array
{
    return $this->resolveActions('formatting');
}

private function resolveActions(string $type): array
{
    $actions = config("filament-max-chat.actions.{$type}", []);
    $result = [];
    
    $chat = $this->activeConversation;
    $chatUser = $chat?->interlocutor();
    
    foreach ($actions as $action) {
        // Проверка видимости
        if (isset($action['visible'])) {
            $visible = is_callable($action['visible']) 
                ? $action['visible']($chat, $chatUser) 
                : $action['visible'];
            if (! $visible) continue;
        }
        
        // Проверка прав
        if (isset($action['permission'])) {
            if (! auth()->user()?->can($action['permission'])) {
                continue;
            }
        }
        
        // Построение URL
        if (isset($action['url'])) {
            $url = is_callable($action['url'])
                ? $action['url']($chat, $chatUser)
                : $action['url'];
        } else {
            $url = '#';
        }
        
        $result[] = array_merge($action, ['url' => $url]);
    }
    
    return $result;
}
```

### Шаг 4. Обновить шаблон для рендеринга действий из конфига

**Файл:** `resources/views/components/operator-chat.blade.php`

В шапке добавить рендер действий:

```blade
@if ($this->canAnswer)
    <div class="flex shrink-0 items-center gap-1">
        @stack('filament-max-chat.header-actions-start')
        @foreach ($this->headerActions as $action)
            <a href="{{ $action['url'] }}" 
               @if($action['open_new_tab'] ?? false) target="_blank" rel="noopener" @endif
               class="shrink-0 rounded-md p-1.5 text-gray-950 hover:bg-gray-100 dark:text-white dark:hover:bg-white/10"
               title="{{ $action['label'] }}"
               @if(isset($action['permission']) && !auth()->user()?->can($action['permission'])) style="display:none" @endif>
                @if(isset($action['icon']))
                    <x-dynamic-component :component="$action['icon']" class="h-5 w-5" />
                @else
                    <span class="text-xs px-2">{{ $action['label'] }}</span>
                @endif
            </a>
        @endforeach
        <!-- существующие кнопки -->
        ...
        @stack('filament-max-chat.header-actions')
    </div>
@endif
```

Аналогично для `formattingActions` в блоке форматирования.

### Шаг 5. Добавить право `chat.manage` в конфиг

**Файл:** `config/filament-max-chat.php`

```php
'permissions' => [
    'view' => env('FILAMENT_MAX_CHAT_PERMISSION_VIEW', 'chat.view'),
    'answer' => env('FILAMENT_MAX_CHAT_PERMISSION_ANSWER', 'chat.answer'),
    'manage' => env('FILAMENT_MAX_CHAT_PERMISSION_MANAGE', 'chat.manage'),
],
```

Обновить `.env.example` при необходимости.

### Шаг 6. Добавить переводы для новых элементов (опционально)

**Файлы:** `lang/ru/chat.php`, `lang/en/chat.php`

Могут потребоваться ключи для дефолтных названий кнопок, но лучше дать хосту самому задавать label.

### Шаг 7. Обновить документацию

**Файл:** `README.md`

Добавить раздел "Расширение интерфейса чата" с примерами использования стеков и конфига.

## Преимущества подхода

- **Обратная совместимость** - ничего не ломается, стеки пустые по умолчанию
- **Гибкость** - можно использовать Blade-стеки для полного контроля или конфиг для простых случаев
- **Без копирования** - не нужно наследовать компонент/шаблон целиком
- **Роуты извне** - хост сам решает куда вести ссылки
- **Доступ к контексту** - во всех точках расширения доступны `$activeChat`, `$chatUser`

## Тестирование

- Проверить что стеки рендерятся
- Проверить работу с конфигом
- Проверить проверку прав
- Проверить передачу данных в callable
- Прогнать Gate

## Риски

- Нужно аккуратно добавить стеки в нужных местах
- Конфиг должен быть backward compatible
- В computed свойствах нужно быть осторожным с производительностью

## Вопросы для уточнения при реализации

- Нужна ли поддержка Livewire actions (wire:click) помимо ссылок?
- Нужна ли поддержка модалок Filament?
- Нужно ли прокидывать дополнительные данные?

Судя по ответам пользователя - достаточно ссылок (a href) на роуты хост-приложения.
