<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToolbox } from '@/Composables/useToolbox';

/**
 * Floating toolbox HUD anchored bottom-right.
 *
 * Shows the player's owned consumables/passives grouped by role.
 * Rendered inside AuthenticatedLayout so it persists across every
 * authed page — not just the map — which matters for future toolbox
 * items like Paper Maps and Seismic Readings that'll ship into this
 * same container.
 *
 * Placement mode is a shared singleton (useToolbox composable) that
 * Map.vue subscribes to: clicking "Place" here sets the mode and
 * switches the drill grid click handler from drill → plant.
 */

interface OwnedItem {
    key: string;
    name: string;
    description: string | null;
    post_type: string;
    quantity: number;
    status: string;
    // Discriminated by `kind` per the project Vue convention
    // (MEMORY.md: no TS object-literal casts in templates).
    effects: Record<string, unknown> | null;
}

const page = usePage();

// owned_items is shared globally by HandleInertiaRequests under
// `auth.owned_items` (as a lazy closure). It's NOT exposed at the top
// level of page.props — reading from there used to silently return
// undefined and leave the dock permanently empty even after the player
// bought items, which made it look like the toolbox was tile-gated.
// Read from the correct path, and fall back to the per-page `state`
// copy that MapStateBuilder adds on /map so a partial reload without
// the auth share still works.
const ownedItems = computed<OwnedItem[]>(() => {
    const auth = page.props.auth as { owned_items?: OwnedItem[] } | undefined;
    if (Array.isArray(auth?.owned_items)) return auth.owned_items;

    const state = page.props.state as { owned_items?: OwnedItem[] } | undefined;
    if (Array.isArray(state?.owned_items)) return state.owned_items;

    return [];
});

const currentTileType = computed<string | null>(() => {
    const state = page.props.state as { current_tile?: { type?: string } } | undefined;
    return state?.current_tile?.type ?? null;
});

const onOilField = computed(() => currentTileType.value === 'oil_field');

const toolbox = useToolbox();

// Toolbox category strings. Items land in a category by looking at
// their effects blob — this classifier is *client-side* so the dock
// stays self-contained and we don't have to ship a second mapping
// from the server.
type ToolboxCategory = 'Sabotage' | 'Counter Measures' | 'Utility';

interface ToolboxGroupItem {
    key: string;
    name: string;
    description: string | null;
    quantity: number;
    deployable: boolean;
    category: ToolboxCategory;
}

function classify(item: OwnedItem): ToolboxCategory | null {
    const effects = item.effects ?? {};
    if (typeof effects['deployable_sabotage'] === 'string') return 'Sabotage';
    if (typeof effects['counter_measure'] === 'string') return 'Counter Measures';
    const unlocks = effects['unlocks'];
    if (Array.isArray(unlocks) && unlocks.includes('sabotage_scanner')) {
        return 'Counter Measures';
    }
    // Reserved for future toolbox citizens (paper maps, seismic reading, fuel cans).
    // Nothing in that bucket today — items land here once they exist.
    return null;
}

const groups = computed<Record<ToolboxCategory, ToolboxGroupItem[]>>(() => {
    const out: Record<ToolboxCategory, ToolboxGroupItem[]> = {
        'Sabotage': [],
        'Counter Measures': [],
        'Utility': [],
    };

    for (const item of ownedItems.value) {
        if (item.status !== 'active') continue;
        if (item.quantity <= 0) continue;
        const category = classify(item);
        if (category === null) continue;
        const effects = item.effects ?? {};
        out[category].push({
            key: item.key,
            name: item.name,
            description: item.description,
            quantity: item.quantity,
            deployable: typeof effects['deployable_sabotage'] === 'string',
            category,
        });
    }

    return out;
});

const totalItems = computed(() => {
    let n = 0;
    for (const group of Object.values(groups.value)) n += group.length;
    return n;
});

const open = ref(false);

function handlePlace(item: ToolboxGroupItem): void {
    if (!item.deployable) return;
    if (!onOilField.value) {
        // Soft fail — no alert, just visible disabled state. The button
        // is disabled when not on an oil field so this branch is only
        // reached if a rogue keybind triggers it.
        return;
    }
    toolbox.enter(item.key, item.name);
    // Collapse the dock so the drill grid is fully visible for clicking.
    open.value = false;
}

function cancelPlacement(): void {
    toolbox.exit();
}
</script>

<template>
    <!-- Anchor wrapper: bottom-right corner on desktop, lifted above the
         mobile bottom tab bar on small screens so the FAB doesn't hide
         behind it. -->
    <div class="fixed bottom-20 right-4 z-40 flex flex-col items-end gap-2 font-mono sm:bottom-4">
        <!-- Placement banner — floats above the dock button when active -->
        <div
            v-if="toolbox.state.placementActive"
            class="max-w-xs rounded-lg border border-amber-500/60 bg-amber-950/90 backdrop-blur px-3 py-2 text-xs text-amber-200 shadow-xl"
        >
            <div class="uppercase tracking-widest text-[10px] text-amber-400 mb-0.5">Placement mode</div>
            <div class="text-sm font-bold">{{ toolbox.state.placementDeviceName }}</div>
            <div class="text-[11px] text-amber-300/80 mt-1">
                Tap a drill point on the 5×5 grid to plant. Press Esc or tap below to cancel.
            </div>
            <button
                type="button"
                class="mt-2 rounded border border-amber-700 px-2 py-0.5 text-[10px] uppercase tracking-widest text-amber-300 hover:border-amber-400 hover:text-amber-100 transition"
                @click="cancelPlacement"
            >
                Cancel
            </button>
        </div>

        <!-- Desktop expanded panel — anchored inline in the corner. -->
        <div
            v-if="open"
            class="hidden w-72 max-h-[60vh] overflow-y-auto rounded-lg border-2 border-amber-500/40 bg-zinc-900/95 backdrop-blur shadow-2xl shadow-amber-900/20 sm:block"
        >
            <div class="flex items-center justify-between border-b border-zinc-800 px-3 py-2">
                <div class="text-amber-400 text-xs uppercase tracking-[0.25em] font-bold">Toolbox</div>
                <button
                    type="button"
                    class="text-zinc-500 hover:text-amber-400 transition"
                    @click="open = false"
                    aria-label="Close toolbox"
                >
                    ✕
                </button>
            </div>

            <div v-if="totalItems === 0" class="px-4 py-8 text-center text-xs text-zinc-500 italic">
                Your toolbox is empty. Buy sabotage or counter-measure items at the General Store.
            </div>

            <div v-else class="divide-y divide-zinc-800">
                <template v-for="(items, cat) in groups" :key="cat">
                    <div v-if="items.length > 0" class="py-2">
                        <div class="px-3 pb-1 text-[10px] uppercase tracking-widest text-zinc-500">{{ cat }}</div>
                        <div
                            v-for="item in items"
                            :key="item.key"
                            class="px-3 py-2 flex items-start gap-2 hover:bg-zinc-800/40 transition"
                        >
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2">
                                    <span class="text-zinc-100 text-sm font-bold truncate">{{ item.name }}</span>
                                    <span class="text-amber-400 text-[10px] uppercase tracking-widest shrink-0">×{{ item.quantity }}</span>
                                </div>
                                <div v-if="item.description" class="text-[11px] text-zinc-500 mt-0.5 line-clamp-2">
                                    {{ item.description }}
                                </div>
                            </div>
                            <button
                                v-if="item.deployable"
                                type="button"
                                class="shrink-0 rounded bg-amber-500 hover:bg-amber-400 disabled:bg-zinc-700 disabled:text-zinc-500 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-zinc-950 transition"
                                :disabled="!onOilField"
                                :title="onOilField ? 'Plant this device on the drill grid' : 'Travel to an oil field to plant this'"
                                @click="handlePlace(item)"
                            >
                                Place
                            </button>
                            <div
                                v-else
                                class="shrink-0 rounded border border-zinc-700 px-2 py-1 text-[10px] uppercase tracking-widest text-zinc-500"
                                title="Passive — no action needed"
                            >
                                Passive
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- FAB / pill trigger. Smaller round FAB on mobile, full pill on desktop. -->
        <button
            type="button"
            class="tap-target relative rounded-full border-2 border-amber-500/60 bg-zinc-900/95 backdrop-blur shadow-xl shadow-amber-900/20 text-amber-400 active:border-amber-400 active:text-amber-300 hover:border-amber-400 hover:text-amber-300 transition flex items-center justify-center gap-2 h-12 w-12 sm:h-auto sm:w-auto sm:px-4 sm:py-3"
            :class="{ 'ring-2 ring-amber-500/40': toolbox.state.placementActive }"
            :aria-label="'Toolbox' + (totalItems > 0 ? ' (' + totalItems + ' items)' : '')"
            @click="open = !open"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 7h18v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" />
                <path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
            </svg>
            <span class="hidden sm:inline text-[11px] uppercase tracking-widest font-bold">Toolbox</span>
            <span
                v-if="totalItems > 0"
                class="absolute -top-1 -right-1 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-amber-500 text-zinc-950 text-[10px] font-bold px-1 py-0.5 sm:static sm:min-w-0 sm:px-1.5"
            >
                {{ totalItems }}
            </span>
        </button>
    </div>

    <!-- Mobile bottom sheet — teleported to body, full-width slide-up. -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-[60] bg-zinc-950/70 backdrop-blur-sm sm:hidden"
                @click.self="open = false"
                aria-hidden="true"
            ></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-200 ease-out"
            enter-from-class="translate-y-full"
            enter-to-class="translate-y-0"
            leave-active-class="transition-transform duration-150 ease-in"
            leave-from-class="translate-y-0"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="open"
                role="dialog"
                aria-modal="true"
                aria-label="Toolbox"
                class="fixed inset-x-0 bottom-0 z-[60] max-h-[80vh] overflow-y-auto rounded-t-2xl border-t-2 border-amber-500/40 bg-zinc-950 shadow-2xl safe-bottom font-mono sm:hidden"
            >
                <div class="mx-auto max-w-xl px-4 pb-4 pt-3">
                    <div
                        class="mx-auto mb-3 h-1 w-12 rounded-full bg-zinc-700"
                        aria-hidden="true"
                    ></div>
                    <div class="flex items-center justify-between border-b border-zinc-800 pb-2 mb-2">
                        <div class="text-amber-400 text-xs uppercase tracking-[0.25em] font-bold">Toolbox</div>
                        <button
                            type="button"
                            class="tap-target text-zinc-400 active:text-amber-300"
                            @click="open = false"
                            aria-label="Close toolbox"
                        >
                            ✕
                        </button>
                    </div>

                    <div v-if="totalItems === 0" class="px-4 py-8 text-center text-sm text-zinc-500 italic">
                        Your toolbox is empty. Buy sabotage or counter-measure items at the General Store.
                    </div>

                    <div v-else class="divide-y divide-zinc-800">
                        <template v-for="(items, cat) in groups" :key="cat">
                            <div v-if="items.length > 0" class="py-2">
                                <div class="px-1 pb-1 text-[10px] uppercase tracking-widest text-zinc-500">{{ cat }}</div>
                                <div
                                    v-for="item in items"
                                    :key="item.key"
                                    class="px-1 py-3 flex items-start gap-3"
                                >
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-baseline gap-2">
                                            <span class="text-zinc-100 text-sm font-bold">{{ item.name }}</span>
                                            <span class="text-amber-400 text-[10px] uppercase tracking-widest shrink-0">×{{ item.quantity }}</span>
                                        </div>
                                        <div v-if="item.description" class="text-xs text-zinc-500 mt-0.5">
                                            {{ item.description }}
                                        </div>
                                    </div>
                                    <button
                                        v-if="item.deployable"
                                        type="button"
                                        class="tap-target shrink-0 rounded bg-amber-500 active:bg-amber-400 disabled:bg-zinc-700 disabled:text-zinc-500 px-3 text-[11px] font-bold uppercase tracking-widest text-zinc-950 transition"
                                        :disabled="!onOilField"
                                        @click="handlePlace(item)"
                                    >
                                        Place
                                    </button>
                                    <div
                                        v-else
                                        class="shrink-0 self-center rounded border border-zinc-700 px-2 py-1 text-[10px] uppercase tracking-widest text-zinc-500"
                                    >
                                        Passive
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
