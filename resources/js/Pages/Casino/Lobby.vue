<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface CasinoSession {
    id: number;
    expires_at: string;
}

interface PlayerState {
    oil_barrels: number;
    akzar_cash: number;
}

interface TileDetail {
    kind: string;
    name: string;
    entry_fee_barrels: number;
    casino_enabled: boolean;
}

interface MapState {
    player: PlayerState;
    current_tile: { type: string };
    tile_detail: TileDetail | null;
}

const props = defineProps<{
    state: MapState;
    casino_session: CasinoSession | null;
}>();

const page = usePage();
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const flash = computed(() => (page.props.flash as Record<string, unknown>) ?? {});
const casinoEntered = computed(() => flash.value.casino_entered as { fee_charged: number; expires_at: string } | undefined);
const casinoError = computed(() => errors.value.casino ?? null);

const hasSession = computed(() => props.casino_session !== null);
const casinoName = computed(() => (props.state.tile_detail as TileDetail)?.name ?? "Roughneck's Saloon");
const entryFee = computed(() => (props.state.tile_detail as TileDetail)?.entry_fee_barrels ?? 50);
const canAfford = computed(() => props.state.player.oil_barrels >= entryFee.value);

function enterCasino() {
    router.post(route('casino.enter'), {}, { preserveScroll: true, preserveState: false });
}

const games = [
    {
        key: 'slots',
        name: 'Slot Machines',
        description: '3-reel slots. Spin and win.',
        route: 'casino.slots.show',
        available: true,
        players: 'Solo',
    },
    {
        key: 'roulette',
        name: 'Roulette',
        description: 'European single-zero. Place your bets.',
        route: null,
        available: false,
        players: 'Group',
    },
    {
        key: 'blackjack',
        name: 'Blackjack',
        description: 'Beat the dealer. 3:2 blackjack.',
        route: null,
        available: false,
        players: 'Group',
    },
    {
        key: 'holdem',
        name: "Texas Hold'em",
        description: 'No-limit poker. Player vs player.',
        route: null,
        available: false,
        players: 'Group',
    },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head :title="casinoName" />

        <div class="mx-auto max-w-4xl px-4 py-6">
            <!-- Casino header -->
            <div class="mb-6 text-center">
                <h1 class="text-2xl font-bold text-amber-400">{{ casinoName }}</h1>
                <p class="mt-1 text-sm text-zinc-500">All casinos are interlinked — same tables, any location.</p>
            </div>

            <!-- Entry gate -->
            <div v-if="!hasSession" class="mx-auto max-w-md rounded-lg border border-zinc-700 bg-zinc-800/60 p-6 text-center">
                <p class="text-zinc-300">
                    Entry fee: <span class="font-semibold text-amber-400">{{ entryFee.toLocaleString() }} barrels</span>
                </p>
                <p class="mt-1 text-xs text-zinc-500">
                    Your balance: {{ state.player.oil_barrels.toLocaleString() }} barrels
                </p>

                <div v-if="casinoError" class="mt-3 rounded bg-red-900/30 px-3 py-2 text-sm text-red-400">
                    {{ casinoError }}
                </div>

                <button
                    :disabled="!canAfford"
                    class="mt-4 rounded-lg px-6 py-2.5 text-sm font-semibold transition-colors"
                    :class="canAfford
                        ? 'bg-amber-600 text-white hover:bg-amber-500'
                        : 'cursor-not-allowed bg-zinc-700 text-zinc-500'"
                    @click="enterCasino"
                >
                    Enter Casino
                </button>

                <div class="mt-4">
                    <Link :href="route('map.show')" class="text-xs text-zinc-500 hover:text-zinc-300">
                        Back to Map
                    </Link>
                </div>
            </div>

            <!-- Casino lobby (session active) -->
            <div v-else>
                <CasinoNav current-page="lobby" />

                <div v-if="casinoEntered && casinoEntered.fee_charged > 0" class="mt-4 rounded bg-green-900/30 px-3 py-2 text-sm text-green-400">
                    Paid {{ casinoEntered.fee_charged.toLocaleString() }} barrels entry fee.
                </div>

                <!-- Player balances -->
                <div class="mt-4 flex gap-4 rounded-lg border border-zinc-700/50 bg-zinc-800/40 px-4 py-3">
                    <div>
                        <span class="text-xs text-zinc-500">Cash</span>
                        <p class="font-semibold text-amber-400">A{{ Number(state.player.akzar_cash).toFixed(2) }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-zinc-500">Oil</span>
                        <p class="font-semibold text-amber-400">{{ state.player.oil_barrels.toLocaleString() }} bbl</p>
                    </div>
                </div>

                <!-- Game list -->
                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="game in games"
                        :key="game.key"
                        class="rounded-lg border p-4 transition-colors"
                        :class="game.available
                            ? 'border-zinc-700 bg-zinc-800/60 hover:border-amber-600/40'
                            : 'border-zinc-800 bg-zinc-900/40 opacity-50'"
                    >
                        <div class="flex items-start justify-between">
                            <h3 class="font-semibold text-zinc-100">{{ game.name }}</h3>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                :class="game.players === 'Solo'
                                    ? 'bg-zinc-700 text-zinc-400'
                                    : 'bg-amber-900/40 text-amber-400'"
                            >
                                {{ game.players }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-zinc-500">{{ game.description }}</p>
                        <div class="mt-3">
                            <Link
                                v-if="game.available && game.route"
                                :href="route(game.route)"
                                class="rounded bg-amber-600 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-amber-500"
                            >
                                Play
                            </Link>
                            <span v-else class="text-xs text-zinc-600">Coming Soon</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
