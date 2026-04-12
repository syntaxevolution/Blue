<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import CardHand from '@/Components/Casino/CardHand.vue';
import ChipSelector from '@/Components/Casino/ChipSelector.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface CardDisplay { rank: string; suit: string; display: string }

interface HandInfo {
    seat: number;
    player_id: number;
    cards: CardDisplay[];
    total: number;
    soft: boolean;
    bet: number;
    status: string;
    payout: number | null;
    outcome: string | null;
}

interface TableState {
    table_id: number;
    currency: string;
    min_bet: number;
    max_bet: number;
    phase: string;
    round_number: number;
    current_seat: number | null;
    hands: HandInfo[];
    dealer: { cards: CardDisplay[]; total: number | null } | null;
    my_seat: number | null;
    is_my_turn: boolean;
}

const props = defineProps<{ state: any; table: TableState }>();

const page = usePage();
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const bjError = computed(() => errors.value.blackjack ?? null);

const bet = ref(props.table.currency === 'akzar_cash' ? 1.00 : 100);
const isWaiting = computed(() => ['waiting', 'betting'].includes(props.table.phase));
const isMyTurn = computed(() => props.table.is_my_turn);
const myHand = computed(() => props.table.hands.find(h => h.seat === props.table.my_seat));

function formatAmount(v: number): string {
    return props.table.currency === 'akzar_cash' ? `A${v.toFixed(2)}` : `${v.toLocaleString()} bbl`;
}

function joinTable() {
    router.post(route('casino.blackjack.join', props.table.table_id), {}, { preserveScroll: true, preserveState: false });
}

function placeBet() {
    router.post(route('casino.blackjack.bet', props.table.table_id), { amount: bet.value }, { preserveScroll: true, preserveState: false });
}

function doAction(action: string) {
    router.post(route('casino.blackjack.action', props.table.table_id), { action }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Blackjack" />
        <div class="mx-auto max-w-3xl px-4 py-6">
            <CasinoNav current-page="blackjack" />

            <div class="mt-6 text-center">
                <h1 class="text-xl font-bold text-amber-400">Blackjack</h1>
                <p class="text-xs text-zinc-500">{{ formatAmount(table.min_bet) }} &ndash; {{ formatAmount(table.max_bet) }}</p>
            </div>

            <div v-if="bjError" class="mt-3 rounded bg-red-900/30 px-3 py-2 text-sm text-red-400">{{ bjError }}</div>

            <!-- Dealer hand -->
            <div class="mt-6 flex justify-center">
                <CardHand
                    v-if="table.dealer"
                    :cards="table.dealer.cards"
                    label="Dealer"
                    :total="table.dealer.total"
                />
                <div v-else class="text-sm text-zinc-600">Waiting for hand...</div>
            </div>

            <!-- Player hands -->
            <div class="mt-6 flex flex-wrap justify-center gap-6">
                <div v-for="hand in table.hands" :key="hand.seat" class="flex flex-col items-center">
                    <CardHand
                        :cards="hand.cards"
                        :label="hand.player_id === state.player.id ? 'You' : `Seat ${hand.seat + 1}`"
                        :total="hand.total"
                        :highlight="hand.seat === table.current_seat"
                    />
                    <div class="mt-1 text-xs">
                        <span class="text-zinc-500">Bet: {{ formatAmount(hand.bet) }}</span>
                        <span v-if="hand.outcome" class="ml-2"
                            :class="{
                                'text-green-400': ['win', 'blackjack'].includes(hand.outcome),
                                'text-amber-400': hand.outcome === 'push',
                                'text-red-400': ['bust', 'loss'].includes(hand.outcome),
                                'text-zinc-400': hand.outcome === 'surrendered',
                            }"
                        >
                            {{ hand.outcome.toUpperCase() }}
                            <span v-if="hand.payout"> ({{ formatAmount(hand.payout) }})</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="mt-6 rounded-xl border border-zinc-700 bg-zinc-800/60 p-4">
                <!-- Not seated -->
                <div v-if="table.my_seat === null" class="text-center">
                    <button @click="joinTable"
                        class="rounded-lg bg-amber-600 px-6 py-2 text-sm font-semibold text-white hover:bg-amber-500">
                        Sit Down
                    </button>
                </div>

                <!-- Waiting / betting -->
                <div v-else-if="isWaiting" class="space-y-3">
                    <ChipSelector v-model="bet"
                        :currency="table.currency as 'akzar_cash' | 'oil_barrels'"
                        :min="table.min_bet" :max="table.max_bet" />
                    <div class="flex justify-center">
                        <button @click="placeBet"
                            class="rounded-lg bg-amber-600 px-8 py-2.5 text-sm font-bold text-white hover:bg-amber-500">
                            Place Bet
                        </button>
                    </div>
                </div>

                <!-- My turn -->
                <div v-else-if="isMyTurn" class="flex flex-wrap justify-center gap-2">
                    <button @click="doAction('hit')"
                        class="rounded bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600">Hit</button>
                    <button @click="doAction('stand')"
                        class="rounded bg-zinc-600 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-500">Stand</button>
                    <button v-if="myHand && myHand.cards.length === 2" @click="doAction('double')"
                        class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">Double</button>
                    <button v-if="myHand && myHand.cards.length === 2" @click="doAction('surrender')"
                        class="rounded bg-red-800 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Surrender</button>
                </div>

                <!-- Waiting for others -->
                <div v-else class="text-center text-sm text-zinc-500">
                    {{ table.phase === 'dealer_turn' ? 'Dealer playing...' : 'Waiting for other players...' }}
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
