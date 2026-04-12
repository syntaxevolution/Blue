<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import CardHand from '@/Components/Casino/CardHand.vue';
import ChipSelector from '@/Components/Casino/ChipSelector.vue';
import TableChat from '@/Components/Casino/TableChat.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useCasinoTableStore } from '@/stores/casinoTable';

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
const flash = computed(() => (page.props.flash as Record<string, unknown>) ?? {});
const bjError = computed(() => errors.value.blackjack ?? null);
const bjResult = computed(() => flash.value.blackjack_result as { action?: string; dealer_total?: number; dealer_bust?: boolean; results?: Array<{ player_id: number; outcome: string; bet: number; payout: number }> } | undefined);

const store = useCasinoTableStore();

onMounted(() => {
    const userId = (page.props.auth as any)?.user?.id ?? null;
    store.subscribe(props.table.table_id, userId);
});

onBeforeUnmount(() => {
    store.unsubscribe();
});

const bet = ref(props.table.currency === 'akzar_cash' ? 1.00 : 100);
const isWaiting = computed(() => ['waiting', 'betting'].includes(props.table.phase));
const isMyTurn = computed(() => props.table.is_my_turn);
const myHand = computed(() => {
    // Use current_seat, not my_seat, because after split the player's
    // active hand is the one with current_seat (not necessarily my_seat).
    if (props.table.current_seat === null) return null;
    return props.table.hands[props.table.current_seat] ?? null;
});

const canDouble = computed(() =>
    myHand.value && myHand.value.cards.length === 2 && myHand.value.status === 'playing'
);

const canSplit = computed(() => {
    if (!myHand.value || myHand.value.cards.length !== 2) return false;
    // Same display rank = pair (10/J/Q/K all count as splittable in typical rules)
    const [c1, c2] = myHand.value.cards;
    const tenRanks = ['10', 'J', 'Q', 'K'];
    if (tenRanks.includes(c1.rank) && tenRanks.includes(c2.rank)) return true;
    return c1.rank === c2.rank;
});

const canSurrender = computed(() =>
    myHand.value && myHand.value.cards.length === 2 && myHand.value.status === 'playing'
);

const canInsurance = computed(() => {
    if (!myHand.value || myHand.value.cards.length !== 2) return false;
    const dealerUp = props.table.dealer?.cards[0];
    return dealerUp?.rank === 'A';
});

function formatAmount(v: number): string {
    return props.table.currency === 'akzar_cash' ? `A${v.toFixed(2)}` : `${v.toLocaleString()} bbl`;
}

function joinTable() {
    router.post(route('casino.blackjack.join', props.table.table_id), {}, { preserveScroll: true, preserveState: true });
}

function placeBet() {
    router.post(route('casino.blackjack.bet', props.table.table_id), { amount: bet.value }, { preserveScroll: true, preserveState: true });
}

function doAction(action: string) {
    router.post(route('casino.blackjack.action', props.table.table_id), { action }, { preserveScroll: true, preserveState: true });
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

            <!-- Round result banner -->
            <div v-if="bjResult && bjResult.action === 'round_resolved'" class="mt-3 rounded border border-zinc-700 bg-zinc-800/60 p-3 text-center">
                <p class="text-xs text-zinc-400">
                    Dealer: {{ bjResult.dealer_total }}<span v-if="bjResult.dealer_bust" class="text-red-400"> (bust)</span>
                </p>
                <div class="mt-1 space-y-0.5">
                    <div v-for="r in bjResult.results" :key="r.player_id" class="text-xs">
                        <span class="font-semibold"
                            :class="{
                                'text-green-400': ['win', 'blackjack'].includes(r.outcome),
                                'text-amber-400': r.outcome === 'push',
                                'text-red-400': ['bust', 'loss'].includes(r.outcome),
                                'text-zinc-400': r.outcome === 'surrendered',
                            }"
                        >
                            {{ r.outcome.toUpperCase() }}
                        </span>
                        <span class="text-zinc-500"> — payout {{ formatAmount(r.payout) }}</span>
                    </div>
                </div>
            </div>

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
                    <button v-if="canDouble" @click="doAction('double')"
                        class="rounded bg-amber-700 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">Double</button>
                    <button v-if="canSplit" @click="doAction('split')"
                        class="rounded bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600">Split</button>
                    <button v-if="canInsurance" @click="doAction('insurance')"
                        class="rounded bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-600">Insurance</button>
                    <button v-if="canSurrender" @click="doAction('surrender')"
                        class="rounded bg-red-800 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Surrender</button>
                </div>

                <!-- Waiting for others -->
                <div v-else class="text-center text-sm text-zinc-500">
                    {{ table.phase === 'dealer_turn' ? 'Dealer playing...' : 'Waiting for other players...' }}
                </div>
            </div>
        </div>
        <TableChat :table-id="table.table_id" />
    </AuthenticatedLayout>
</template>
