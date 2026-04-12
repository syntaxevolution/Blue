<script setup lang="ts">
const model = defineModel<'akzar_cash' | 'oil_barrels'>({ required: true });
</script>

<template>
    <div class="inline-flex rounded-lg border border-zinc-700 bg-zinc-800/50 p-0.5">
        <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="model === 'akzar_cash'
                ? 'bg-amber-600 text-white shadow'
                : 'text-zinc-400 hover:text-zinc-200'"
            @click="model = 'akzar_cash'"
        >
            Cash
        </button>
        <button
            type="button"
            class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
            :class="model === 'oil_barrels'
                ? 'bg-amber-600 text-white shadow'
                : 'text-zinc-400 hover:text-zinc-200'"
            @click="model = 'oil_barrels'"
        >
            Oil
        </button>
    </div>
</template>
