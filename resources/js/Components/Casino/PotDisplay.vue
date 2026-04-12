<script setup lang="ts">
defineProps<{
    pot: number;
    currency: string;
}>();

function formatAmount(v: number, c: string): string {
    return c === 'akzar_cash' ? `A${v.toFixed(2)}` : `${v.toLocaleString()} bbl`;
}
</script>

<template>
    <div class="rounded-full border border-amber-600/40 bg-amber-900/20 px-4 py-1.5 text-center">
        <span class="text-[10px] uppercase tracking-wider text-zinc-500">Pot</span>
        <p class="text-sm font-bold text-amber-400">{{ formatAmount(pot, currency) }}</p>
    </div>
</template>
