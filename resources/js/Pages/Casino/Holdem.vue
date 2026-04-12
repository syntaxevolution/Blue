<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import CardHand from '@/Components/Casino/CardHand.vue';
import PlayerSeat from '@/Components/Casino/PlayerSeat.vue';
import PotDisplay from '@/Components/Casino/PotDisplay.vue';
import TurnTimer from '@/Components/Casino/TurnTimer.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface CardDisplay { rank: string; suit: string; display: string }

interface PlayerInfo {
    seat: number; player_id: number; stack: number;
    bet_this_round: number; total_bet: number;
    folded: boolean; all_in: boolean;
    hole_cards: CardDisplay[] | null;
}

interface TableState {
    table_id: number; currency: string; phase: string;
    round_number: number; pot: number; current_bet: number;
    community: CardDisplay[]; players: PlayerInfo[];
    action_on: number | null; dealer_seat: number | null;
    blind_level: { small: number; big: number } | null;
    is_my_turn: boolean; my_seat: number | null;
}

const props = defineProps<{ state: any; table: TableState }>();

const page = usePage();
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const holdemError = computed(() => errors.value.holdem ?? null);

const buyIn = ref(props.table.blind_level ? props.table.blind_level.big * 50 : 5);
const raiseAmount = ref(props.table.current_bet * 2 || (props.table.blind_level?.big ?? 0.10) * 2);

const isSeated = computed(() => props.table.my_seat !== null);
const isMyTurn = computed(() => props.table.is_my_turn);
const isWaiting = computed(() => props.table.phase === 'waiting');
const isPlaying = computed(() => ['pre_flop', 'flop', 'turn', 'river', 'showdown'].includes(props.table.phase));

const myPlayer = computed(() => {
    if (props.table.my_seat === null) return null;
    return props.table.players.find(p => p.seat === props.table.players[props.table.my_seat!]?.seat);
});

const toCall = computed(() => {
    if (!myPlayer.value) return 0;
    return Math.max(0, props.table.current_bet - myPlayer.value.bet_this_round);
});

function formatAmount(v: number): string {
    return props.table.currency === 'akzar_cash' ? `A${v.toFixed(2)}` : `${v.toLocaleString()} bbl`;
}

function joinTable() {
    router.post(route('casino.holdem.join', props.table.table_id),
        { buy_in: buyIn.value }, { preserveScroll: true, preserveState: false });
}

function doAction(action: string, amount: number = 0) {
    router.post(route('casino.holdem.action', props.table.table_id),
        { action, amount }, { preserveScroll: true, preserveState: false });
}

function leaveTable() {
    router.post(route('casino.holdem.leave', props.table.table_id),
        {}, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Texas Hold'em" />
        <div class="mx-auto max-w-4xl px-4 py-6">
            <CasinoNav current-page="holdem" />

            <div class="mt-4 text-center">
                <h1 class="text-xl font-bold text-amber-400">Texas Hold'em</h1>
                <p v-if="table.blind_level" class="text-xs text-zinc-500">
                    Blinds: {{ formatAmount(table.blind_level.small) }} / {{ formatAmount(table.blind_level.big) }}
                </p>
            </div>

            <div v-if="holdemError" class="mt-3 rounded bg-red-900/30 px-3 py-2 text-sm text-red-400">{{ holdemError }}</div>

            <!-- Pot -->
            <div v-if="isPlaying" class="mt-4 flex justify-center">
                <PotDisplay :pot="table.pot" :currency="table.currency" />
            </div>

            <!-- Community cards -->
            <div v-if="table.community.length > 0" class="mt-4 flex justify-center">
                <CardHand :cards="table.community" label="Community" />
            </div>

            <!-- Player seats -->
            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <PlayerSeat
                    v-for="p in table.players"
                    :key="p.seat"
                    :seat="p.seat"
                    :player-id="p.player_id"
                    :stack="p.stack"
                    :bet-this-round="p.bet_this_round"
                    :folded="p.folded"
                    :all-in="p.all_in"
                    :hole-cards="p.hole_cards"
                    :is-current-player="p.player_id === state.player.id"
                    :is-action-on="table.action_on !== null && table.players[table.action_on]?.player_id === p.player_id"
                    :currency="table.currency"
                />
            </div>

            <!-- Turn timer -->
            <div v-if="isMyTurn" class="mt-3 flex justify-center">
                <TurnTimer :seconds="30" :active="true" />
            </div>

            <!-- Controls -->
            <div class="mt-6 rounded-xl border border-zinc-700 bg-zinc-800/60 p-4">
                <!-- Not seated -->
                <div v-if="!isSeated && isWaiting" class="text-center space-y-3">
                    <div class="flex items-center justify-center gap-2">
                        <label class="text-xs text-zinc-500">Buy-in:</label>
                        <input v-model.number="buyIn" type="number" :min="0.01"
                            class="w-28 rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-sm text-zinc-200 focus:border-amber-500 focus:outline-none" />
                    </div>
                    <button @click="joinTable"
                        class="rounded-lg bg-amber-600 px-6 py-2 text-sm font-semibold text-white hover:bg-amber-500">
                        Sit Down
                    </button>
                </div>

                <!-- My turn actions -->
                <div v-else-if="isMyTurn" class="space-y-3">
                    <div class="flex flex-wrap justify-center gap-2">
                        <button @click="doAction('fold')"
                            class="rounded bg-red-800 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Fold</button>
                        <button v-if="toCall === 0" @click="doAction('check')"
                            class="rounded bg-zinc-600 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-500">Check</button>
                        <button v-if="toCall > 0" @click="doAction('call')"
                            class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600">
                            Call {{ formatAmount(toCall) }}
                        </button>
                        <div class="flex items-center gap-1">
                            <input v-model.number="raiseAmount" type="number" :min="table.current_bet * 2"
                                class="w-24 rounded border border-zinc-600 bg-zinc-800 px-2 py-1.5 text-sm text-zinc-200 focus:border-amber-500 focus:outline-none" />
                            <button @click="doAction('raise', raiseAmount)"
                                class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">Raise</button>
                        </div>
                        <button @click="doAction('all_in')"
                            class="rounded bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-500">All In</button>
                    </div>
                </div>

                <!-- Waiting -->
                <div v-else-if="isSeated" class="text-center">
                    <p class="text-sm text-zinc-500">
                        {{ isWaiting ? 'Waiting for more players...' : 'Waiting for your turn...' }}
                    </p>
                    <button @click="leaveTable"
                        class="mt-2 rounded border border-zinc-600 px-3 py-1 text-xs text-zinc-400 hover:border-zinc-500 hover:text-zinc-200">
                        Leave &amp; Cash Out
                    </button>
                </div>

                <div v-else class="text-center text-sm text-zinc-500">
                    Hand in progress — wait for it to finish to join.
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
