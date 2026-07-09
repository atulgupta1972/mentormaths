import { onMounted, onUnmounted, ref } from 'vue';

function readCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

export function useAttemptActiveTimer(attemptId, timing = {}) {
    const elapsed = ref(timing.active_seconds ?? 0);
    const sessionStartedAt = timing.active_session_started_at
        ? new Date(timing.active_session_started_at).getTime()
        : Date.now();

    let timer = null;
    let pauseSent = false;

    const tick = () => {
        if (pauseSent || document.hidden) {
            return;
        }

        const base = timing.active_seconds ?? 0;
        elapsed.value = base + Math.floor((Date.now() - sessionStartedAt) / 1000);
    };

    const pause = () => {
        if (pauseSent || !attemptId) {
            return;
        }

        pauseSent = true;

        const url = route('student.attempts.timing.pause', attemptId);
        const body = new URLSearchParams({ _token: readCsrfToken() });

        if (navigator.sendBeacon) {
            navigator.sendBeacon(url, body);
        } else {
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-TOKEN': readCsrfToken(),
                    Accept: 'application/json',
                },
                body: body.toString(),
                keepalive: true,
            }).catch(() => {});
        }
    };

    const onVisibilityChange = () => {
        if (document.hidden) {
            pause();
        }
    };

    onMounted(() => {
        tick();
        timer = setInterval(tick, 1000);
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('pagehide', pause);
    });

    onUnmounted(() => {
        if (timer) {
            clearInterval(timer);
        }

        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.removeEventListener('pagehide', pause);
        pause();
    });

    const formatTime = (seconds) => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;

        return `${m}:${String(s).padStart(2, '0')}`;
    };

    return { elapsed, formatTime, pause };
}
