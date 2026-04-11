import { reactive, readonly, onMounted, onBeforeUnmount } from 'vue';

export interface ToastPayload {
    id: string;
    type: string;
    title: string;
    body: Record<string, unknown> | null;
    timestamp: string;
}

interface NotificationState {
    toasts: ToastPayload[];
}

const state = reactive<NotificationState>({
    toasts: [],
});

let toastCounter = 0;

export function pushToast(t: Omit<ToastPayload, 'id'>): void {
    const id = `toast-${Date.now()}-${toastCounter++}`;
    state.toasts.push({ ...t, id });

    // Auto-expire after 8s so the stack doesn't grow forever.
    setTimeout(() => {
        const idx = state.toasts.findIndex((x) => x.id === id);
        if (idx >= 0) state.toasts.splice(idx, 1);
    }, 8000);
}

export function dismissToast(id: string): void {
    const idx = state.toasts.findIndex((x) => x.id === id);
    if (idx >= 0) state.toasts.splice(idx, 1);
}

/**
 * Tracks the currently-subscribed user so we can tear down the
 * previous channel before subscribing to a new one. Prevents
 * duplicate toasts on HMR or layout re-mount.
 */
let currentSubscribedUserId: number | null = null;

/**
 * Subscribe to the current user's private channel and convert
 * broadcast events into toast notifications.
 *
 * Call from AuthenticatedLayout.vue onMounted. userId is 0 / null
 * when unauthenticated — in that case we skip subscription entirely.
 *
 * Idempotent: calling twice for the same user is a no-op; calling
 * with a different user tears down the previous channel first.
 */
export function subscribeToUserNotifications(userId: number | null | undefined): () => void {
    if (!userId || typeof window === 'undefined' || !window.Echo) {
        return () => undefined;
    }

    // If we're already subscribed to this user, return the same noop
    // (the caller will still get a teardown fn, but it points at the
    // shared channel we already own).
    if (currentSubscribedUserId === userId) {
        return () => {
            if (currentSubscribedUserId !== userId) return;
            try {
                window.Echo.leave(`App.Models.User.${userId}`);
            } catch {
                // ignore teardown errors
            }
            currentSubscribedUserId = null;
        };
    }

    // Tear down any previous subscription before swapping.
    if (currentSubscribedUserId !== null) {
        try {
            window.Echo.leave(`App.Models.User.${currentSubscribedUserId}`);
        } catch {
            // ignore
        }
    }

    currentSubscribedUserId = userId;
    const channel = window.Echo.private(`App.Models.User.${userId}`);

    const handle = (payload: Omit<ToastPayload, 'id'>) => {
        pushToast(payload);
    };

    channel.listen('.BaseUnderAttack', handle);
    channel.listen('.SpyDetected', handle);
    channel.listen('.RaidCompleted', handle);
    channel.listen('.MdnEvent', handle);

    return () => {
        if (currentSubscribedUserId !== userId) return;
        try {
            window.Echo.leave(`App.Models.User.${userId}`);
        } catch {
            // ignore teardown errors
        }
        currentSubscribedUserId = null;
    };
}

export function useNotifications() {
    let unsubscribe: () => void = () => undefined;

    return {
        toasts: readonly(state.toasts),
        dismiss: dismissToast,
        push: pushToast,
        mount(userId: number | null | undefined) {
            onMounted(() => {
                unsubscribe = subscribeToUserNotifications(userId);
            });
            onBeforeUnmount(() => {
                unsubscribe();
            });
        },
    };
}
