import { onMounted, onUnmounted, ref } from 'vue';
import { isAttemptFullscreenActive } from '@/utils/attemptFullscreen';

const COPY_KEYS = new Set(['c', 'x', 'v', 'a']);
const BLOCKED_KEYS = new Set(['p', 's', 'u']);

function readCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function isEditableTarget(target) {
    if (!target || !(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
}

function shouldBlockShortcut(event) {
    if (!event.ctrlKey && !event.metaKey) {
        if (event.key === 'F12') {
            return true;
        }

        return false;
    }

    const key = event.key.toLowerCase();

    if (COPY_KEYS.has(key) || BLOCKED_KEYS.has(key)) {
        return true;
    }

    return event.shiftKey && ['i', 'j', 'c'].includes(key);
}

function recordTabLeave(attemptId) {
    if (!attemptId) {
        return;
    }

    const url = route('student.attempts.integrity.tab-leave', attemptId);
    const body = new URLSearchParams({ _token: readCsrfToken() });

    if (navigator.sendBeacon) {
        navigator.sendBeacon(url, body);

        return;
    }

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

/**
 * @param {{
 *   mode?: 'strict'|'light'|'off',
 *   attemptId?: number|null,
 *   trackTabLeaves?: boolean,
 *   locksOnTabLeaves?: boolean,
 *   initialTabLeaveCount?: number,
 *   lockLimit?: number,
 *   initiallyLocked?: boolean,
 *   requireFullscreen?: boolean,
 * }} options
 */
export function useAttemptContentProtection(options = {}) {
    const mode = options.mode ?? 'off';
    const attemptId = options.attemptId ?? null;
    const trackTabLeaves = options.trackTabLeaves ?? mode !== 'off';
    const locksOnTabLeaves = options.locksOnTabLeaves ?? mode === 'strict';
    const requireFullscreen = options.requireFullscreen ?? false;
    const lockLimit = Math.max(1, options.lockLimit ?? 4);
    const strict = mode === 'strict';
    const enabled = mode !== 'off';
    const blockContent = enabled;

    const contentHidden = ref(false);
    const tabLeaveCount = ref(options.initialTabLeaveCount ?? 0);
    const attemptLocked = ref(
        options.initiallyLocked
            ?? (locksOnTabLeaves && trackTabLeaves && (options.initialTabLeaveCount ?? 0) >= lockLimit),
    );

    let lastLeaveAt = 0;
    let leaveTrackingSuspended = false;
    let wasFullscreen = isAttemptFullscreenActive();

    const preventDefault = (event) => {
        event.preventDefault();
    };

    const onCopy = (event) => {
        event.preventDefault();
    };

    const onPaste = (event) => {
        event.preventDefault();
    };

    const onKeyDown = (event) => {
        if (isEditableTarget(event.target)) {
            if (shouldBlockShortcut(event)) {
                event.preventDefault();
            }

            return;
        }

        if (shouldBlockShortcut(event)) {
            event.preventDefault();
        }
    };

    const noteLeave = () => {
        contentHidden.value = true;

        if (!trackTabLeaves || attemptLocked.value || leaveTrackingSuspended) {
            return;
        }

        const now = Date.now();
        if (now - lastLeaveAt < 1500) {
            return;
        }
        lastLeaveAt = now;

        tabLeaveCount.value += 1;
        recordTabLeave(attemptId);

        if (locksOnTabLeaves && tabLeaveCount.value >= lockLimit) {
            attemptLocked.value = true;
        }
    };

    const suspendLeaveTracking = () => {
        leaveTrackingSuspended = true;
    };

    const resumeLeaveTracking = () => {
        // Ignore blur/focus churn from native confirm() closing.
        leaveTrackingSuspended = true;
        window.setTimeout(() => {
            leaveTrackingSuspended = false;
            lastLeaveAt = Date.now();
        }, 800);
    };

    /**
     * Run a native confirm without counting the dialog blur as a tab leave.
     */
    const confirmWithoutLeave = (message) => {
        suspendLeaveTracking();
        try {
            return window.confirm(message);
        } finally {
            resumeLeaveTracking();
        }
    };

    const onVisibilityChange = () => {
        if (document.hidden) {
            noteLeave();
        } else if (!attemptLocked.value && (!requireFullscreen || isAttemptFullscreenActive())) {
            contentHidden.value = false;
        }
    };

    /** Side panels (Gemini) / other windows on the same screen. */
    const onWindowBlur = () => {
        if (document.hidden || leaveTrackingSuspended) {
            return;
        }

        noteLeave();
    };

    const onWindowFocus = () => {
        if (!attemptLocked.value && !document.hidden && (!requireFullscreen || isAttemptFullscreenActive())) {
            contentHidden.value = false;
        }
    };

    const onFullscreenChange = () => {
        const active = isAttemptFullscreenActive();

        if (requireFullscreen && wasFullscreen && !active) {
            noteLeave();
        }

        wasFullscreen = active;

        if (active && !attemptLocked.value && !document.hidden) {
            contentHidden.value = false;
        }
    };

    const onSelectStart = (event) => {
        if (isEditableTarget(event.target)) {
            return;
        }

        event.preventDefault();
    };

    onMounted(() => {
        if (!enabled) {
            return;
        }

        document.addEventListener('visibilitychange', onVisibilityChange);
        window.addEventListener('blur', onWindowBlur);
        window.addEventListener('focus', onWindowFocus);
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);

        if (blockContent) {
            document.addEventListener('copy', onCopy, true);
            document.addEventListener('cut', onCopy, true);
            document.addEventListener('paste', onPaste, true);
            document.addEventListener('contextmenu', preventDefault, true);
            document.addEventListener('keydown', onKeyDown, true);
            document.addEventListener('dragstart', preventDefault, true);
            document.addEventListener('selectstart', onSelectStart, true);
        }
    });

    onUnmounted(() => {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        window.removeEventListener('blur', onWindowBlur);
        window.removeEventListener('focus', onWindowFocus);
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
        document.removeEventListener('copy', onCopy, true);
        document.removeEventListener('cut', onCopy, true);
        document.removeEventListener('paste', onPaste, true);
        document.removeEventListener('contextmenu', preventDefault, true);
        document.removeEventListener('keydown', onKeyDown, true);
        document.removeEventListener('dragstart', preventDefault, true);
        document.removeEventListener('selectstart', onSelectStart, true);
    });

    return {
        contentHidden,
        tabLeaveCount,
        attemptLocked,
        lockLimit,
        enabled,
        strict,
        confirmWithoutLeave,
        suspendLeaveTracking,
        resumeLeaveTracking,
    };
}
