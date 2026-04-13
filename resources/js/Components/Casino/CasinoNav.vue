<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    currentPage: 'lobby' | 'slots' | 'roulette' | 'blackjack' | 'holdem';
}>();
</script>

<template>
    <nav class="flex flex-nowrap items-center gap-3 overflow-x-auto border-b border-zinc-700/50 bg-zinc-900/60 px-3 py-2 sm:px-4">
        <Link
            :href="route('casino.show')"
            class="shrink-0 rounded px-3 py-2 text-sm font-medium transition-colors"
            :class="currentPage === 'lobby'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Lobby
        </Link>
        <Link
            :href="route('casino.slots.show')"
            class="shrink-0 rounded px-3 py-2 text-sm font-medium transition-colors"
            :class="currentPage === 'slots'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Slots
        </Link>
        <Link
            :href="route('casino.roulette.index')"
            class="shrink-0 rounded px-3 py-2 text-sm font-medium transition-colors"
            :class="currentPage === 'roulette'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Roulette
        </Link>
        <Link
            :href="route('casino.blackjack.index')"
            class="shrink-0 rounded px-3 py-2 text-sm font-medium transition-colors"
            :class="currentPage === 'blackjack'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Blackjack
        </Link>
        <Link
            :href="route('casino.holdem.index')"
            class="shrink-0 rounded px-3 py-2 text-sm font-medium transition-colors"
            :class="currentPage === 'holdem'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Hold'em
        </Link>

        <div class="shrink-0 sm:ml-auto">
            <Link
                :href="route('casino.leave')"
                method="post"
                :as="'button'"
                class="shrink-0 rounded border border-zinc-600 px-3 py-2 text-xs text-zinc-400 transition-colors hover:border-zinc-500 hover:text-zinc-200 active:border-zinc-500"
            >
                Leave Casino
            </Link>
        </div>
    </nav>
</template>
