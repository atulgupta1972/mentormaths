export function isAttemptFullscreenActive() {
    return Boolean(document.fullscreenElement);
}

export async function requestAttemptFullscreen() {
    if (isAttemptFullscreenActive()) {
        return true;
    }

    const element = document.documentElement;

    try {
        if (element.requestFullscreen) {
            await element.requestFullscreen();
        } else if (element.webkitRequestFullscreen) {
            await element.webkitRequestFullscreen();
        } else {
            return false;
        }

        return isAttemptFullscreenActive();
    } catch {
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
    }
}
