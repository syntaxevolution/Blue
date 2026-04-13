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

/**
 * Live-increment deltas for the navbar unread badges.
 *
 * The authoritative unread counts are server-rendered into
 * page.props.auth on every Inertia visit. Between visits, broadcast
 * events fire and we want the badge to bump immediately (before the
 * player navigates), so we track an additive delta here. The layout
 * adds `serverCount + delta` for the displayed value and resets the
 * delta to 0 whenever the server count changes — at that point the
 * fresh prop already reflects the events we were counting locally.
 */
export const badgeDeltas = reactive<{ activity: number; hostility: number }>({
    activity: 0,
    hostility: 0,
});

export function resetActivityBadgeDelta(): void {
    badgeDeltas.activity = 0;
}

export function resetHostilityBadgeDelta(): void {
    badgeDeltas.hostility = 0;
}

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

    // Every broadcast event bumps the activity badge; the subset that
    // represents harm done to the viewer (`BaseUnderAttack`,
    // `RigSabotaged`, `TileCombatResolved`) ALSO bumps the hostility
    // badge so the /attack-log nav link lights up in sync with the
    // toast. Matches AttackLogService::recentAttacks() — anything
    // rendered in that feed maps to one of these three event types.
    const activityOnly = (payload: Omit<ToastPayload, 'id'>) => {
        pushToast(payload);
        badgeDeltas.activity += 1;
    };

    const activityAndHostility = (payload: Omit<ToastPayload, 'id'>) => {
        pushToast(payload);
        badgeDeltas.activity += 1;
        badgeDeltas.hostility += 1;
    };

    channel.listen('.BaseUnderAttack', activityAndHostility);
    channel.listen('.SpyDetected', activityOnly);
    channel.listen('.RaidCompleted', activityOnly);
    channel.listen('.MdnEvent', activityOnly);
    // Sabotage — pair of events. SabotageTriggered goes to the planter
    // when their device fires (they caused harm, not suffered it, so
    // activity-only); RigSabotaged goes to the victim (harm suffered,
    // so it also bumps hostility).
    channel.listen('.SabotageTriggered', activityOnly);
    channel.listen('.RigSabotaged', activityAndHostility);
    // Wasteland tile combat — defender-side event only. Defender has
    // suffered harm → bumps hostility alongside activity.
    channel.listen('.TileCombatResolved', activityAndHostility);

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
