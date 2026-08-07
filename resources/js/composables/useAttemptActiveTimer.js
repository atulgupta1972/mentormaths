import { onMounted, onUnmounted, ref } from 'vue';

function readCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function postTiming(url) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': readCsrfToken(),
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: '{}',
    });

    if (! response.ok) {
        return null;
    }

    return response.json();
}

export function useAttemptActiveTimer(attemptId, timing = {}) {
    const elapsed = ref(timing.active_seconds ?? 0);
    let baseSeconds = timing.active_seconds ?? 0;
    let sessionStartedAt = timing.active_session_started_at
        ? new Date(timing.active_session_started_at).getTime()
        : Date.now();

    let timer = null;
    let heartbeatTimer = null;
    let pauseSent = false;

    const tick = () => {
        if (pauseSent || document.hidden) {
            return;
        }

        elapsed.value = baseSeconds + Math.floor((Date.now() - sessionStartedAt) / 1000);
    };

    const applyPayload = (payload) => {
        if (! payload) {
            return;
        }

        baseSeconds = payload.active_seconds ?? baseSeconds;
        if (payload.active_session_started_at) {
            sessionStartedAt = new Date(payload.active_session_started_at).getTime();
            pauseSent = false;
        }
        tick();
    };

    const pause = () => {
        if (pauseSent || ! attemptId) {
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

    const resume = async () => {
        if (! attemptId || document.hidden) {
            return;
        }

        try {
            const payload = await postTiming(route('student.attempts.timing.resume', attemptId));
            applyPayload(payload);
            pauseSent = false;
        } catch {
            pauseSent = false;
            sessionStartedAt = Date.now();
        }
    };

    const heartbeat = async () => {
        if (! attemptId || document.hidden || pauseSent) {
            return;
        }

        try {
            const payload = await postTiming(route('student.attempts.timing.heartbeat', attemptId));
            applyPayload(payload);
        } catch {
            // ignore transient network errors
        }
    };

    const onVisibilityChange = () => {
        if (document.hidden) {
            pause();
        } else {
            resume();
        }
    };

    onMounted(() => {
        tick();
        timer = setInterval(tick, 1000);
        heartbeatTimer = setInterval(heartbeat, 30000);
        heartbeat();
        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('pagehide', pause);
    });

    onUnmounted(() => {
        if (timer) {
            clearInterval(timer);
        }
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
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
