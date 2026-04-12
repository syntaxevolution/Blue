<script setup lang="ts">
import CardHand from './CardHand.vue';

interface CardDisplay { rank: string; suit: string; display: string }

defineProps<{
    seat: number;
    playerId: number;
    stack: number;
    betThisRound: number;
    folded: boolean;
    allIn: boolean;
    holeCards: CardDisplay[] | null;
    isCurrentPlayer: boolean;
    isActionOn: boolean;
    currency: string;
}>();

function formatAmount(v: number, c: string): string {
    return c === 'akzar_cash' ? `A${v.toFixed(2)}` : `${v.toLocaleString()}`;
}
</script>

<template>
    <div
        class="flex flex-col items-center rounded-lg border p-2 transition-all"
        :class="{
            'border-amber-500 bg-amber-900/20': isActionOn,
            'border-zinc-700 bg-zinc-800/60': !isActionOn && !folded,
            'border-zinc-800 bg-zinc-900/40 opacity-50': folded,
        }"
    >
        <span class="mb-1 text-[10px] font-medium uppercase tracking-wider"
            :class="isCurrentPlayer ? 'text-amber-400' : 'text-zinc-500'">
            {{ isCurrentPlayer ? 'You' : `Seat ${seat + 1}` }}
        </span>

        <div v-if="holeCards && holeCards.length > 0" class="mb-1">
            <CardHand :cards="holeCards" />
        </div>
        <div v-else class="mb-1 flex gap-1">
            <div class="h-16 w-11 rounded border border-amber-800 bg-amber-900/40"></div>
            <div class="h-16 w-11 rounded border border-amber-800 bg-amber-900/40"></div>
        </div>

        <div class="text-xs">
            <span class="text-zinc-400">{{ formatAmount(stack, currency) }}</span>
            <span v-if="betThisRound > 0" class="ml-1 text-amber-400">
                ({{ formatAmount(betThisRound, currency) }})
            </span>
        </div>

        <span v-if="folded" class="mt-0.5 text-[10px] text-red-400">FOLDED</span>
        <span v-else-if="allIn" class="mt-0.5 text-[10px] font-bold text-amber-400">ALL IN</span>
    </div>
</template>
