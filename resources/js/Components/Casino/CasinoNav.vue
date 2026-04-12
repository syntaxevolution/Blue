<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    currentPage: 'lobby' | 'slots' | 'roulette' | 'blackjack' | 'holdem';
}>();
</script>

<template>
    <nav class="flex items-center gap-3 border-b border-zinc-700/50 bg-zinc-900/60 px-4 py-2">
        <Link
            :href="route('casino.show')"
            class="rounded px-2.5 py-1 text-sm font-medium transition-colors"
            :class="currentPage === 'lobby'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Lobby
        </Link>
        <Link
            :href="route('casino.slots.show')"
            class="rounded px-2.5 py-1 text-sm font-medium transition-colors"
            :class="currentPage === 'slots'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Slots
        </Link>
        <Link
            :href="route('casino.roulette.index')"
            class="rounded px-2.5 py-1 text-sm font-medium transition-colors"
            :class="currentPage === 'roulette'
                ? 'bg-amber-600/20 text-amber-400'
                : 'text-zinc-400 hover:text-zinc-200'"
        >
            Roulette
        </Link>
        <span class="rounded px-2.5 py-1 text-sm text-zinc-600 cursor-not-allowed">
            Blackjack
        </span>
        <span class="rounded px-2.5 py-1 text-sm text-zinc-600 cursor-not-allowed">
            Hold'em
        </span>

        <div class="ml-auto">
            <Link
                :href="route('casino.leave')"
                method="post"
                :as="'button'"
                class="rounded border border-zinc-600 px-3 py-1 text-xs text-zinc-400 transition-colors hover:border-zinc-500 hover:text-zinc-200"
            >
                Leave Casino
            </Link>
        </div>
    </nav>
</template>
