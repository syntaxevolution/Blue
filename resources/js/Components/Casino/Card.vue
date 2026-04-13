<script setup lang="ts">
const props = defineProps<{
    rank: string;
    suit: string;
    faceDown?: boolean;
}>();

const suitColor: Record<string, string> = {
    hearts: 'text-red-500',
    diamonds: 'text-red-500',
    clubs: 'text-zinc-100',
    spades: 'text-zinc-100',
};

const suitSymbol: Record<string, string> = {
    clubs: '♣', diamonds: '♦', hearts: '♥', spades: '♠',
};
</script>

<template>
    <div
        class="relative flex h-16 w-11 flex-col items-center justify-center rounded-md border shadow-sm sm:h-20 sm:w-14"
        :class="faceDown
            ? 'border-amber-700 bg-amber-900/60'
            : 'border-zinc-600 bg-zinc-800'"
    >
        <template v-if="faceDown || rank === '?'">
            <span class="text-base sm:text-lg text-amber-500/60">?</span>
        </template>
        <template v-else>
            <span class="text-[10px] sm:text-xs font-bold" :class="suitColor[suit] ?? 'text-zinc-100'">
                {{ rank }}
            </span>
            <span class="text-base sm:text-lg" :class="suitColor[suit] ?? 'text-zinc-100'">
                {{ suitSymbol[suit] ?? suit }}
            </span>
        </template>
    </div>
</template>
