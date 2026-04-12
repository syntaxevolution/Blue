<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    currency: 'akzar_cash' | 'oil_barrels';
    min: number;
    max: number;
}>();

const model = defineModel<number>({ required: true });

const presets = computed(() => {
    if (props.currency === 'akzar_cash') {
        return [0.10, 0.50, 1, 5, 10, 50, 100].filter(v => v >= props.min && v <= props.max);
    }
    return [10, 50, 100, 500, 1000, 5000, 10000].filter(v => v >= props.min && v <= props.max);
});

const symbol = computed(() => props.currency === 'akzar_cash' ? 'A' : '');
const suffix = computed(() => props.currency === 'oil_barrels' ? ' bbl' : '');

function formatChip(v: number): string {
    if (props.currency === 'akzar_cash') {
        return `A${v < 1 ? v.toFixed(2) : v.toLocaleString()}`;
    }
    return `${v.toLocaleString()} bbl`;
}
</script>

<template>
    <div class="space-y-2">
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="preset in presets"
                :key="preset"
                type="button"
                class="rounded-md border px-2.5 py-1 text-xs font-medium transition-colors"
                :class="model === preset
                    ? 'border-amber-500 bg-amber-600/20 text-amber-400'
                    : 'border-zinc-600 bg-zinc-800 text-zinc-300 hover:border-zinc-500'"
                @click="model = preset"
            >
                {{ formatChip(preset) }}
            </button>
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-zinc-500">Custom:</label>
            <input
                v-model.number="model"
                type="number"
                :min="min"
                :max="max"
                :step="currency === 'akzar_cash' ? 0.01 : 1"
                class="w-28 rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-sm text-zinc-200 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
            />
        </div>
    </div>
</template>
