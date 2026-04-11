<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    brokenItemKey: string;
    itemName?: string | null;
    repairCost?: number | null;
    playerBarrels?: number | null;
}>();

const repairForm = useForm({});
const abandonForm = useForm({});

const canAffordRepair = computed(() => {
    if (props.repairCost == null || props.playerBarrels == null) return true;
    return props.playerBarrels >= props.repairCost;
});

function repair() {
    repairForm.post(route('items.repair'), { preserveScroll: true });
}

function abandon() {
    abandonForm.post(route('items.abandon'), { preserveScroll: true });
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-zinc-950/90 backdrop-blur">
        <div class="w-full max-w-md rounded-lg border border-rose-600/50 bg-zinc-900 p-6 shadow-2xl">
            <h2 class="font-mono text-xl font-bold uppercase tracking-widest text-rose-400 mb-2">
                Broken: {{ itemName ?? brokenItemKey }}
            </h2>
            <p class="text-zinc-400 text-sm mb-5 leading-relaxed">
                Your tech snapped mid-use. You cannot take any other action
                until you decide what to do with it.
            </p>

            <div class="flex flex-col gap-3">
                <button
                    type="button"
                    class="rounded-md bg-amber-500 px-4 py-3 font-mono text-sm font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition disabled:opacity-30 disabled:cursor-not-allowed"
                    :disabled="!canAffordRepair || repairForm.processing"
                    @click="repair"
                >
                    Repair<span v-if="repairCost != null"> ({{ repairCost }} barrels)</span>
                </button>

                <button
                    type="button"
                    class="rounded-md border border-rose-700 bg-rose-950/60 px-4 py-3 font-mono text-sm font-bold uppercase tracking-wider text-rose-300 hover:bg-rose-900/60 transition"
                    @click="abandon"
                >
                    Abandon
                </button>

                <div
                    v-if="!canAffordRepair"
                    class="text-rose-400 text-xs font-mono text-center"
                >
                    Not enough barrels to repair — you must abandon.
                </div>
            </div>
        </div>
    </div>
</template>
