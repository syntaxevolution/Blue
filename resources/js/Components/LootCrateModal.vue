<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Discovered vs Resolved modal states.
 *
 *  discovered — player just walked onto (or manually clicked) a
 *               crate. Two buttons: Open / Leave it. Skipping
 *               dismisses the modal but does NOT destroy the crate
 *               (the server leaves both real and sabotage crates
 *               on the tile until opened).
 *
 *  resolved   — open() succeeded, we're showing what was inside.
 *               Single "Continue" button.
 */

interface LootResult {
    kind: string;
    barrels?: number;
    cash?: number;
    item_key?: string;
    item_name?: string;
    reason?: string;
    sabotage_device_key?: string;
    sabotage_kind?: string;
    amount?: number;
    steal_pct?: number;
    victim_before?: number;
}

const props = defineProps<{
    crateId: number;
    placedByMe: boolean;
    resolved: LootResult | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const inFlight = ref(false);

const title = computed<string>(() => {
    if (props.resolved) {
        return resolvedTitle(props.resolved);
    }
    return 'You found a loot crate';
});

const body = computed<string>(() => {
    if (props.resolved) {
        return resolvedBody(props.resolved);
    }
    if (props.placedByMe) {
        return "This is your own sabotage crate. You can see it sitting here, but you can't open it — it's waiting for a rival.";
    }
    return "A sealed crate is sitting in the dust. It could be a stash of gear... or a trap. You can open it now, or leave it where it is — it'll still be here for the next visitor.";
});

const canOpen = computed(() => !props.placedByMe);

function resolvedTitle(r: LootResult): string {
    switch (r.kind) {
        case 'oil':
            return `+${r.barrels ?? 0} barrels`;
        case 'cash':
            return `+A${(r.cash ?? 0).toFixed(2)}`;
        case 'item':
            return `Found: ${r.item_name ?? 'an item'}`;
        case 'item_dupe':
            return `Duplicate: ${r.item_name ?? 'item'}`;
        case 'nothing':
            return 'Empty crate';
        case 'sabotage_oil':
            return 'It was a trap!';
        case 'sabotage_cash':
            return 'It was a trap!';
        case 'immune_no_effect':
            return 'Trap fizzled';
        default:
            return 'Crate opened';
    }
}

function resolvedBody(r: LootResult): string {
    switch (r.kind) {
        case 'oil':
            return `Inside the crate: ${r.barrels ?? 0} barrels of crude. Straight into your tanks.`;
        case 'cash':
            return `Inside the crate: A${(r.cash ?? 0).toFixed(2)} in loose bills. Counted and pocketed.`;
        case 'item':
            return `Inside the crate: ${r.item_name ?? 'an item'}. Added to your toolbox.`;
        case 'item_dupe':
            return `Inside the crate: ${r.item_name ?? 'an item'} — but you already own one. Out of luck this time.`;
        case 'nothing':
            return 'You pry the crate open. It was empty. Someone got here before you.';
        case 'sabotage_oil':
            return `It was a trap! ${r.amount ?? 0} barrels drained straight out of your stash into someone else's account.`;
        case 'sabotage_cash':
            return `It was a trap! A${(r.amount ?? 0).toFixed(2)} siphoned out of your cash into an anonymous account.`;
        case 'immune_no_effect':
            return 'That crate was a trap — but your new-player immunity held. The mechanism fizzled harmlessly.';
        default:
            return 'The crate opened.';
    }
}

function open() {
    if (!canOpen.value || inFlight.value) {
        return;
    }
    inFlight.value = true;
    router.post(
        route('map.loot_crates.open', { crate: props.crateId }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                inFlight.value = false;
            },
        },
    );
}

function leave() {
    // "Leave it" is a purely client-side dismissal: the crate
    // persists on the server until someone explicitly opens it
    // (per spec — both real and sabotage crates stay on the tile),
    // so there is nothing to POST. The decline endpoint exists
    // for API symmetry / future analytics, but the web/mobile UX
    // doesn't need to call it on the happy path. Skipping the
    // round-trip avoids a wasted request and the "wait for the
    // server to roundtrip before the modal closes" stutter.
    emit('close');
}

function dismiss() {
    if (inFlight.value) {
        return;
    }
    emit('close');
}

// Escape-to-close: matches the existing Modal.vue convention so
// every modal in the app responds to the same shortcut.
function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && !inFlight.value) {
        e.preventDefault();
        emit('close');
    }
}
onMounted(() => document.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown));

const isHostileOutcome = computed<boolean>(() => {
    if (!props.resolved) {
        return false;
    }
    return ['sabotage_oil', 'sabotage_cash'].includes(props.resolved.kind);
});

const isPositiveOutcome = computed<boolean>(() => {
    if (!props.resolved) {
        return false;
    }
    return ['oil', 'cash', 'item'].includes(props.resolved.kind);
});
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed inset-0 z-[80] flex items-center justify-center bg-black/75 p-4"
            @click.self="dismiss"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="loot-crate-modal-title"
                class="w-full max-w-md rounded-lg border-2 bg-zinc-900 p-5 sm:p-6 font-mono shadow-2xl"
                :class="[
                    resolved && isHostileOutcome
                        ? 'border-rose-500/60 shadow-rose-900/30'
                        : resolved && isPositiveOutcome
                            ? 'border-emerald-500/60 shadow-emerald-900/30'
                            : resolved
                                ? 'border-zinc-600/60 shadow-zinc-900/30'
                                : 'border-amber-500/60 shadow-amber-900/30',
                ]"
            >
                <div
                    class="mb-2 text-xs uppercase tracking-widest"
                    :class="[
                        resolved && isHostileOutcome
                            ? 'text-rose-400'
                            : resolved && isPositiveOutcome
                                ? 'text-emerald-400'
                                : 'text-amber-400',
                    ]"
                >
                    Loot crate
                </div>
                <h2 id="loot-crate-modal-title" class="text-zinc-100 text-2xl font-bold mb-3 break-words">
                    {{ title }}
                </h2>
                <p class="text-zinc-300 text-sm leading-relaxed mb-5">
                    {{ body }}
                </p>

                <div v-if="resolved" class="flex flex-col gap-2">
                    <button
                        type="button"
                        class="w-full rounded border px-4 py-3 text-sm font-bold uppercase tracking-wider transition"
                        :class="[
                            isHostileOutcome
                                ? 'border-rose-600 bg-rose-800 text-zinc-100 hover:bg-rose-700'
                                : isPositiveOutcome
                                    ? 'border-emerald-600 bg-emerald-800 text-zinc-100 hover:bg-emerald-700'
                                    : 'border-zinc-700 bg-zinc-800 text-zinc-200 hover:bg-zinc-700',
                        ]"
                        @click="dismiss"
                    >
                        Continue
                    </button>
                </div>

                <div v-else class="flex flex-col gap-2 sm:flex-row">
                    <button
                        type="button"
                        class="flex-1 rounded border border-zinc-700 bg-zinc-800 px-4 py-3 text-sm font-bold uppercase tracking-wider text-zinc-300 transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="inFlight"
                        @click="leave"
                    >
                        Leave it
                    </button>
                    <button
                        v-if="canOpen"
                        type="button"
                        class="flex-1 rounded border border-amber-500 bg-amber-500 px-4 py-3 text-sm font-bold uppercase tracking-wider text-zinc-950 transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="inFlight"
                        @click="open"
                    >
                        {{ inFlight ? 'Opening…' : 'Open it' }}
                    </button>
                </div>

                <div v-if="placedByMe && !resolved" class="mt-3 text-[11px] italic text-amber-400/80">
                    You placed this crate. Only a rival can trigger it.
                </div>
            </div>
        </div>
    </Teleport>
</template>
