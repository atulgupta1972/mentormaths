/**
 * Attempt fullscreen helpers.
 *
 * iPad/iOS Safari often exits the Fullscreen API when the on-screen keyboard
 * opens for fill-in-blank inputs. Treat that as still "in attempt" — do not
 * re-show the fullscreen gate or count a tab leave.
 */

const SESSION_KEY = 'mm_attempt_immersive_ok';

export function isEditableFocusTarget(target = document.activeElement) {
    if (!target || !(target instanceof HTMLElement)) {
        return false;
    }

    const tag = target.tagName;

    return tag === 'INPUT' || tag === 'TEXTAREA' || target.isContentEditable;
}

export function isAppleTouchDevice() {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const ua = navigator.userAgent || '';
    const iOS = /iPad|iPhone|iPod/.test(ua);
    // iPadOS 13+ may report as MacIntel with touch points.
    const iPadOs = navigator.platform === 'MacIntel' && (navigator.maxTouchPoints || 0) > 1;

    return iOS || iPadOs;
}

export function supportsFullscreenApi() {
    if (typeof document === 'undefined') {
        return false;
    }

    const el = document.documentElement;

    return Boolean(
        el.requestFullscreen
        || el.webkitRequestFullscreen
        || el.msRequestFullscreen,
    );
}

export function isAttemptFullscreenActive() {
    return Boolean(
        document.fullscreenElement
        || document.webkitFullscreenElement
        || document.mozFullScreenElement
        || document.msFullscreenElement,
    );
}

export function markAttemptImmersiveOk() {
    try {
        sessionStorage.setItem(SESSION_KEY, '1');
    } catch {
        // ignore private-mode / blocked storage
    }
}

export function hasAttemptImmersiveOk() {
    try {
        return sessionStorage.getItem(SESSION_KEY) === '1';
    } catch {
        return false;
    }
}

/**
 * True when the student may continue the attempt without the gate overlay.
 * Native fullscreen OR (after they confirmed once) keyboard / iOS quirks.
 */
export function isAttemptImmersiveSatisfied() {
    if (isAttemptFullscreenActive()) {
        return true;
    }

    // Keyboard open on fill-blank — Safari drops fullscreenElement while typing.
    if (hasAttemptImmersiveOk() && isEditableFocusTarget()) {
        return true;
    }

    // iPad/iPhone: after the student has entered / confirmed once, stay ready
    // even when the API reports not-fullscreen (keyboard, toolbar, split view).
    if (isAppleTouchDevice() && hasAttemptImmersiveOk()) {
        return true;
    }

    return false;
}

/**
 * Fullscreen was lost for a reason we should ignore (typing in a blank).
 */
export function isBenignFullscreenExit() {
    return isEditableFocusTarget() || (isAppleTouchDevice() && hasAttemptImmersiveOk() && !document.hidden);
}

export async function requestAttemptFullscreen() {
    if (isAttemptFullscreenActive()) {
        markAttemptImmersiveOk();

        return true;
    }

    const element = document.documentElement;

    try {
        if (element.requestFullscreen) {
            try {
                await element.requestFullscreen({ navigationUI: 'hide' });
            } catch {
                await element.requestFullscreen();
            }
        } else if (element.webkitRequestFullscreen) {
            element.webkitRequestFullscreen();
        } else if (element.msRequestFullscreen) {
            element.msRequestFullscreen();
        } else if (isAppleTouchDevice()) {
            // Older WebKit: no reliable Fullscreen API — treat gesture as OK.
            markAttemptImmersiveOk();

            return true;
        } else {
            return false;
        }

        await new Promise((resolve) => setTimeout(resolve, 50));

        if (isAttemptFullscreenActive()) {
            markAttemptImmersiveOk();

            return true;
        }

        // iPad sometimes accepts the gesture but never sets fullscreenElement.
        if (isAppleTouchDevice()) {
            markAttemptImmersiveOk();

            return true;
        }

        return false;
    } catch {
        if (isAppleTouchDevice()) {
            markAttemptImmersiveOk();

            return true;
        }

        return false;
    }
}

export function exitAttemptFullscreen() {
    if (!isAttemptFullscreenActive()) {
        return;
    }

    if (document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
    } else if (document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
    } else if (document.msExitFullscreen) {
        document.msExitFullscreen();
    }
}
