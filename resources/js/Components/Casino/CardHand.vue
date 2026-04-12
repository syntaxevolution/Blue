<script setup lang="ts">
import Card from './Card.vue';

defineProps<{
    cards: Array<{ rank: string; suit: string; display: string }>;
    label?: string;
    total?: number | null;
    highlight?: boolean;
}>();
</script>

<template>
    <div class="flex flex-col items-center gap-1">
        <span v-if="label" class="text-[10px] font-medium uppercase tracking-wider text-zinc-500">
            {{ label }}
        </span>
        <div class="flex gap-1">
            <Card
                v-for="(card, i) in cards"
                :key="i"
                :rank="card.rank"
                :suit="card.suit"
                :face-down="card.rank === '?'"
            />
        </div>
        <span
            v-if="total !== undefined && total !== null"
            class="mt-0.5 text-xs font-semibold"
            :class="highlight ? 'text-amber-400' : 'text-zinc-400'"
        >
            {{ total }}
        </span>
    </div>
</template>
