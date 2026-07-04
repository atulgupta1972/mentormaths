/**
 * Avoid blank screens when prod route cache is stale and Ziggy lacks a new route name.
 */
export function hasRoute(name) {
    try {
        return typeof route === 'function' && route().has(name);
    } catch {
        return false;
    }
}

export function safeRoute(name, params, fallback = '#') {
    try {
        if (hasRoute(name)) {
            return route(name, params);
        }
    } catch {
        // Ignore — fall through to fallback.
    }

    return fallback;
}

export function assignToClassPath(gradeLevelId) {
    return `/admin/classes/${gradeLevelId}/assign`;
}

export function assignToClassStorePath(gradeLevelId) {
    return `/admin/classes/${gradeLevelId}/assign`;
}
