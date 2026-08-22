export function isAttemptFullscreenActive() {
    return Boolean(
        document.fullscreenElement
        || document.webkitFullscreenElement
        || document.mozFullScreenElement
        || document.msFullscreenElement,
    );
}

export async function requestAttemptFullscreen() {
    if (isAttemptFullscreenActive()) {
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
        } else {
            return false;
        }

        await new Promise((resolve) => setTimeout(resolve, 50));

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
    } else if (document.msExitFullscreen) {
        document.msExitFullscreen();
    }
}
