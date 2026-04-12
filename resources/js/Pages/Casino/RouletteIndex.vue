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
    status: string;
}

defineProps<{
    state: any;
    casino_session: { id: number; expires_at: string } | null;
    tables: TableInfo[];
}>();

function formatBet(amount: number, currency: string): string {
    return currency === 'akzar_cash' ? `A${amount.toFixed(2)}` : `${amount.toLocaleString()} bbl`;
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Roulette Tables" />

        <div class="mx-auto max-w-4xl px-4 py-6">
            <CasinoNav current-page="roulette" />

            <div class="mt-6 text-center">
                <h1 class="text-xl font-bold text-amber-400">Roulette Tables</h1>
                <p class="mt-1 text-xs text-zinc-500">European single-zero &mdash; 2.7% house edge</p>
            </div>

            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div
                    v-for="table in tables"
                    :key="table.id"
                    class="rounded-lg border border-zinc-700 bg-zinc-800/60 p-4"
                >
                    <h3 class="font-semibold text-zinc-100">{{ table.label }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        Min: {{ formatBet(table.min_bet, table.currency) }}
                        &middot; Max: {{ formatBet(table.max_bet, table.currency) }}
                    </p>
                    <div class="mt-1">
                        <span class="inline-block rounded-full px-2 py-0.5 text-[10px]"
                            :class="table.status === 'active'
                                ? 'bg-green-900/40 text-green-400'
                                : 'bg-zinc-700 text-zinc-400'"
                        >
                            {{ table.status === 'active' ? 'Betting Open' : 'Waiting' }}
                        </span>
                    </div>
                    <div class="mt-3">
                        <Link
                            :href="route('casino.roulette.show', table.id)"
                            class="rounded bg-amber-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-amber-500"
                        >
                            Join Table
                        </Link>
                    </div>
                </div>
            </div>

            <div v-if="tables.length === 0" class="mt-8 text-center text-zinc-500">
                No roulette tables available. Check back later.
            </div>
        </div>
    </AuthenticatedLayout>
</template>
