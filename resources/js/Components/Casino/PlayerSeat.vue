<script setup lang="ts">
import { computed } from 'vue';
import CardHand from './CardHand.vue';

interface CardDisplay { rank: string; suit: string; display: string }

const props = defineProps<{
    seat: number;
    playerId: number;
    stack: number;
    betThisRound: number;
    folded: boolean;
    allIn: boolean;
    holeCards: CardDisplay[] | null;
    isCurrentPlayer: boolean;
    isActionOn: boolean;
    isDealer?: boolean;
    currency: string;
}>();

// Defense-in-depth: only render real card faces for the local player.
// At showdown, the backend sends faces for non-current players too —
// those are checked for '?' rank by the card component anyway.
const visibleCards = computed<CardDisplay[] | null>(() => {
    if (!props.holeCards) return null;
    if (props.isCurrentPlayer) return props.holeCards;
    // Other players: only allow cards through if the backend explicitly
    // included non-'?' ranks (i.e., we're at showdown). Otherwise blank.
    const allRevealed = props.holeCards.every(c => c.rank !== '?');
    return allRevealed ? props.holeCards : null;
});

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
        <div class="mb-1 flex items-center gap-1">
            <span class="text-[10px] font-medium uppercase tracking-wider"
                :class="isCurrentPlayer ? 'text-amber-400' : 'text-zinc-500'">
                {{ isCurrentPlayer ? 'You' : `Seat ${seat + 1}` }}
            </span>
            <span v-if="isDealer"
                class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-400 text-[9px] font-bold text-zinc-900"
                title="Dealer">D</span>
        </div>

        <div v-if="visibleCards && visibleCards.length > 0" class="mb-1">
            <CardHand :cards="visibleCards" />
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
