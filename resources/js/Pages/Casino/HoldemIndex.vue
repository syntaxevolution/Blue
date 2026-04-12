<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface BlindLevel { small: number; big: number }
interface TableInfo {
    id: number; currency: string; label: string; status: string;
    players: number; seats: number; blind_level: BlindLevel | null;
}

defineProps<{ state: any; tables: TableInfo[] }>();

const page = usePage();
const rakePct = computed(() =>
    Number((page.props.game as { holdem_rake_pct?: number } | undefined)?.holdem_rake_pct ?? 0.05),
);

function formatBlinds(bl: BlindLevel | null, c: string): string {
    if (!bl) return '—';
    return c === 'akzar_cash'
        ? `A${bl.small.toFixed(2)}/A${bl.big.toFixed(2)}`
        : `${bl.small}/${bl.big} bbl`;
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Hold'em Tables" />
        <div class="mx-auto max-w-4xl px-4 py-6">
            <CasinoNav current-page="holdem" />
            <div class="mt-6 text-center">
                <h1 class="text-xl font-bold text-amber-400">Texas Hold'em</h1>
                <p class="mt-1 text-xs text-zinc-500">No-limit poker &mdash; player vs player, rake {{ (rakePct * 100).toFixed(1) }}%</p>
            </div>
            <div class="mt-6 grid gap-4 sm:grid-cols-2">
                <div v-for="table in tables" :key="table.id" class="rounded-lg border border-zinc-700 bg-zinc-800/60 p-4">
                    <h3 class="font-semibold text-zinc-100">{{ table.label }}</h3>
                    <p class="mt-1 text-sm text-zinc-500">
                        Blinds: {{ formatBlinds(table.blind_level, table.currency) }}
                        &middot; {{ table.players }}/{{ table.seats }} seats
                    </p>
                    <div class="mt-3">
                        <Link :href="route('casino.holdem.show', table.id)"
                            class="rounded bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-500">
                            Join Table
                        </Link>
                    </div>
                </div>
            </div>
            <div v-if="tables.length === 0" class="mt-8 text-center text-zinc-500">
                No Hold'em tables available yet.
            </div>
        </div>
    </AuthenticatedLayout>
</template>
