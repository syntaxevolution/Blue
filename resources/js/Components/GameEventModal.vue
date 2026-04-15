<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';

/**
 * Generic full-screen popup modal used by Map.vue to surface
 * action results (raid outcomes, purchase confirmations, drill
 * breaks, sabotage hits) and errors.
 *
 * Replaces the stacked inline banner system that used to live
 * at the top of the map page — banners shifted the layout on
 * every action, which was especially bad on mobile where the
 * drill grid would jump under the player's finger.
 *
 * Design notes:
 *  - <Teleport to="body"> escapes any ancestor stacking context
 *    so mobile Safari can't trap the fixed positioning behind
 *    a transformed parent. Matches the tile-combat result modal
 *    pattern already used elsewhere in Map.vue.
 *  - z-[80] sits above MobileMoreDrawer (z-60), ToolboxDock
 *    (z-60), and the toast container (z-70).
 *  - Escape-to-close is registered globally while the modal is
 *    mounted so the keyboard shortcut matches Modal.vue /
 *    LootCrateModal / BrokenItemModal.
 *  - Click-outside the card closes the modal (background click
 *    handler on the backdrop via @click.self).
 *  - `kind` drives the accent colour: success=emerald,
 *    error=rose, warning=amber, info=sky, neutral=zinc.
 */
type PopupKind = 'success' | 'error' | 'warning' | 'info' | 'neutral';

const props = defineProps<{
    kind: PopupKind;
    title: string;
    body: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const palette = computed(() => {
    switch (props.kind) {
        case 'success':
            return {
                border: 'border-emerald-500/60',
                shadow: 'shadow-emerald-900/30',
                label: 'text-emerald-400',
                button: 'border-emerald-600 bg-emerald-800 hover:bg-emerald-700 text-zinc-100',
                labelText: 'Success',
            };
        case 'error':
            return {
                border: 'border-rose-500/60',
                shadow: 'shadow-rose-900/30',
                label: 'text-rose-400',
                button: 'border-rose-600 bg-rose-800 hover:bg-rose-700 text-zinc-100',
                labelText: 'Error',
            };
        case 'warning':
            return {
                border: 'border-amber-500/60',
                shadow: 'shadow-amber-900/30',
                label: 'text-amber-400',
                button: 'border-amber-500 bg-amber-500 hover:bg-amber-400 text-zinc-950',
                labelText: 'Heads up',
            };
        case 'info':
            return {
                border: 'border-sky-500/60',
                shadow: 'shadow-sky-900/30',
                label: 'text-sky-400',
                button: 'border-sky-600 bg-sky-800 hover:bg-sky-700 text-zinc-100',
                labelText: 'Info',
            };
        default:
            return {
                border: 'border-zinc-600/60',
                shadow: 'shadow-zinc-900/30',
                label: 'text-zinc-400',
                button: 'border-zinc-700 bg-zinc-800 hover:bg-zinc-700 text-zinc-200',
                labelText: 'Notice',
            };
    }
});

function dismiss(): void {
    emit('close');
}

function handleKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') {
        e.preventDefault();
        dismiss();
    }
}
onMounted(() => document.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', handleKeydown));
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
                aria-labelledby="game-event-modal-title"
                class="w-full max-w-md rounded-lg border-2 bg-zinc-900 p-5 sm:p-6 font-mono shadow-2xl"
                :class="[palette.border, palette.shadow]"
            >
                <div class="mb-2 text-xs uppercase tracking-widest" :class="palette.label">
                    {{ palette.labelText }}
                </div>
                <h2
                    id="game-event-modal-title"
                    class="text-zinc-100 text-xl sm:text-2xl font-bold mb-3 break-words"
                >
                    {{ title }}
                </h2>
                <p class="text-zinc-300 text-sm leading-relaxed mb-5 whitespace-pre-line">
                    {{ body }}
                </p>
                <button
                    type="button"
                    class="w-full rounded border px-4 py-3 text-sm font-bold uppercase tracking-wider transition"
                    :class="palette.button"
                    @click="dismiss"
                >
                    Continue
                </button>
            </div>
        </div>
    </Teleport>
</template>
