@props([
    'channel' => null,
])

@if(config('filament-max-chat.notifications.enabled'))
<script>
(function () {
    const CHANNEL = @json($channel ?? config('filament-max-chat.broadcast_channel'));
    const SOUND_ENABLED_DEFAULT = @json(config('filament-max-chat.notifications.sound', true));
    const BROWSER_ENABLED = @json(config('filament-max-chat.notifications.browser', true));
    const STORAGE_KEY = 'filament-max-chat-sound';
    const TOAST_DURATION = 5000;

    function isSoundEnabled() {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === null) return SOUND_ENABLED_DEFAULT;
        return stored === 'true';
    }

    function setSoundEnabled(value) {
        localStorage.setItem(STORAGE_KEY, value ? 'true' : 'false');
    }

    let audioCtx = null;
    function playSound() {
        if (!isSoundEnabled()) return;
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 800;
            gain.gain.value = 0.3;
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.4);
            osc.stop(audioCtx.currentTime + 0.4);
        } catch (e) { /* silent */ }
    }

    let permissionRequested = false;
    function requestNotificationPermission() {
        if (!BROWSER_ENABLED || permissionRequested) return;
        if ('Notification' in window && Notification.permission === 'default') {
            permissionRequested = true;
            Notification.requestPermission();
        }
    }

    function showBrowserNotification(title, body) {
        if (!BROWSER_ENABLED) return;
        if ('Notification' in window && Notification.permission === 'granted') {
            try { new Notification(title, { body: body, icon: document.querySelector('link[rel="icon"]')?.href || '' }); } catch (e) { /* silent */ }
        }
    }

    function showToast(message) {
        const existing = document.getElementById('fmc-toast');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.id = 'fmc-toast';
        toast.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:99999;background:#111827;color:#fff;padding:0.75rem 1.25rem;border-radius:0.5rem;font-size:0.875rem;box-shadow:0 4px 12px rgba(0,0,0,.25);opacity:0;transition:opacity .3s;max-width:360px;';
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(() => { toast.style.opacity = '1'; });
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, TOAST_DURATION);
    }

    function updateBadge(count) {
        const chatLink = document.querySelector('.fi-sidebar-item a[href$="/chat"]');
        if (!chatLink) return;

        const container = chatLink.querySelector('.fi-sidebar-item-badge-ctn');
        if (count > 0) {
            if (container) {
                const badge = container.querySelector('[x-html]');
                if (badge) {
                    badge.setAttribute('x-html', count > 99 ? '99+' : String(count));
                }
            } else {
                const li = chatLink.closest('.fi-sidebar-item');
                if (li) {
                    const span = document.createElement('span');
                    span.className = 'fi-sidebar-item-badge-ctn';
                    span.innerHTML = '<span class="fi-badge fi-badge-size-sm fi-badge-color-danger"><span>' + (count > 99 ? '99+' : String(count)) + '</span></span>';
                    chatLink.appendChild(span);
                }
            }
        } else if (container) {
            container.remove();
        }
    }

    function handleMessage(data) {
        const count = data.unread_count || 0;
        const incomingBotChatId = data.bot_chat_id || 0;
        const activeChatId = window.__fmcActiveChatId || null;
        const isActiveChat = activeChatId !== null && Number(activeChatId) === Number(incomingBotChatId);

        updateBadge(count);

        if (!isActiveChat) {
            playSound();
            showToast(@json(__('filament-max-chat::chat.notification_body')));
            showBrowserNotification(
                @json(__('filament-max-chat::chat.notification_title')),
                @json(__('filament-max-chat::chat.notification_body'))
            );
        }
    }

    function subscribeEcho() {
        if (!window.Echo) {
            document.addEventListener('EchoLoaded', subscribeEcho, { once: true });
            return;
        }
        window.Echo.private(CHANNEL)
            .listen('.chat-message.created', function (e) {
                handleMessage(e);
            });
    }

    subscribeEcho();

    document.addEventListener('click', function handler() {
        requestNotificationPermission();
        document.removeEventListener('click', handler);
    }, { once: true });

    document.addEventListener('click', function (e) {
        if (e.target.closest('#fmc-sound-toggle')) {
            const next = !isSoundEnabled();
            setSoundEnabled(next);
            const btn = document.getElementById('fmc-sound-toggle');
            if (btn) btn.title = next ? @json(__('filament-max-chat::chat.notification_sound_on')) : @json(__('filament-max-chat::chat.notification_sound_off'));
        }
    });
})();
</script>
@endif
