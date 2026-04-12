<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import { Head, Link } from '@inertiajs/vue3';

interface TableInfo {
    id: number;
    currency: string;
    label: string;
    min_bet: number;
    max_bet: number;
    seats: number;
    status: string;
    players: number;
}

defineProps<{ state: any; tables: TableInfo[] }>();

function formatBet(v: number, c: string): string {
    return c === 'akzar_cash' ? `A${v.toFixed(2)}` : `${v.toLocaleString()} bbl`;
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Blackjack Tables" />
        <div class="mx-auto max-w-4xl px-4 py-6">
            <CasinoNav current-page="blackjack" />
            <div class="mt-6 text-center">
                <h1 class="text-xl font-bold text-amber-400">Blackjack Tables</h1>
                <p class="mt-1 text-xs text-zinc-500">Beat the dealer &mdash; 3:2 blackjack, 6-deck shoe</p>
            </div>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div v-for="table in tables" :key="table.id" class="rounded-lg border border-zinc-700 bg-zinc-800/60 p-4">
                    <h3 class="font-semibold text-zinc-100">{{ table.label }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        {{ formatBet(table.min_bet, table.currency) }} &ndash; {{ formatBet(table.max_bet, table.currency) }}
                        &middot; {{ table.players }}/{{ table.seats }} seats
                    </p>
                    <div class="mt-3">
                        <Link :href="route('casino.blackjack.show', table.id)"
                            class="rounded bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-500">
                            Join Table
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
