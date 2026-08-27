@props([
    'channel' => null,
    'slug' => null,
])

@if(config('filament-max-chat.notifications.enabled'))
<script>
(function () {
    const CHANNEL = @json($channel ?? config('filament-max-chat.broadcast_channel'));
    const SLUG = @json($slug ?? config('filament-max-chat.ui.slug', 'chat'));
    const SOUND_ENABLED_DEFAULT = @json(config('filament-max-chat.notifications.sound', true));
    const BROWSER_ENABLED = @json(config('filament-max-chat.notifications.browser', true));
    const STORAGE_KEY = 'filament-max-chat-sound';
    const BADGE_STORAGE_KEY = 'filament-max-chat-unread';
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
            if (audioCtx.state === 'suspended') audioCtx.resume();
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

    function findChatLink() {
        return Array.from(document.querySelectorAll('.fi-sidebar-item-btn'))
            .find((link) => (link.getAttribute('href') || '').endsWith('/' + SLUG)) || null;
    }

    function readSidebarBadgeCount() {
        const chatLink = findChatLink();

        if (!chatLink) {
            return null;
        }

        const label = chatLink.querySelector('.fi-badge-label');

        if (!label) {
            return null;
        }

        const value = parseInt((label.textContent || '').trim(), 10);

        return Number.isFinite(value) ? value : null;
    }

    function applyBadge(count) {
        const chatLink = findChatLink();

        if (!chatLink) {
            return;
        }

        const existing = chatLink.querySelector('.fi-badge-label');
        if (count > 0) {
            if (existing) {
                existing.textContent = count > 99 ? '99+' : String(count);
            } else {
                const badgeCtn = document.createElement('span');
                badgeCtn.className = 'fi-sidebar-item-badge-ctn';
                badgeCtn.innerHTML = '<span class="fi-badge fi-size-md fi-color-danger"><span class="fi-badge-label-ctn"><span class="fi-badge-label">' + (count > 99 ? '99+' : String(count)) + '</span></span></span>';
                chatLink.appendChild(badgeCtn);
            }
        } else if (existing) {
            existing.closest('.fi-sidebar-item-badge-ctn')?.remove();
        }
    }

    function updateBadge(count) {
        localStorage.setItem(BADGE_STORAGE_KEY, String(count));
        applyBadge(count);
    }

    function restoreBadge() {
        const serverCount = readSidebarBadgeCount();
        const count = serverCount ?? (parseInt(localStorage.getItem(BADGE_STORAGE_KEY) || '0', 10) || 0);

        updateBadge(count);
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

    restoreBadge();
    subscribeEcho();

    document.addEventListener('chat-unread', (e) => {
        updateBadge(Number(e.detail.count) || 0);
    });

    document.addEventListener('livewire:navigated', () => {
        restoreBadge();

        if (!document.querySelector('[data-channel]')) {
            window.__fmcActiveChatId = null;
        }
    });

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
