<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import ChipSelector from '@/Components/Casino/ChipSelector.vue';
import RouletteBoard from '@/Components/Casino/RouletteBoard.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useCasinoTableStore } from '@/stores/casinoTable';

interface TableState {
    id: number;
    currency: string;
    min_bet: number;
    max_bet: number;
    phase: string;
    round_number: number;
    expires_at: string | null;
    total_bets: number;
    my_bets: Array<{ id: string; bet_type: string; numbers: number[]; amount: number }>;
}

const props = defineProps<{
    state: any;
    casino_session: { id: number; expires_at: string } | null;
    table: TableState;
}>();

const page = usePage();
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const rouletteError = computed(() => errors.value.roulette ?? null);

const store = useCasinoTableStore();
const bet = ref(props.table.currency === 'akzar_cash' ? 0.10 : 10);
const countdown = ref(0);
let countdownTimer: ReturnType<typeof setInterval> | null = null;

const phase = computed(() => store.phase !== 'idle' ? store.phase : props.table.phase);
const isBetting = computed(() => phase.value === 'betting');
const lastResult = computed(() => store.lastResult);

const RED_NUMBERS = [1,3,5,7,9,12,14,16,18,19,21,23,25,27,30,32,34,36];

function resultColorClass(n: number): string {
    if (n === 0) return 'text-green-400';
    return RED_NUMBERS.includes(n) ? 'text-red-400' : 'text-zinc-100';
}

function startCountdown(expiresAt: string) {
    if (countdownTimer) clearInterval(countdownTimer);

    function tick() {
        const diff = Math.max(0, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000));
        countdown.value = diff;
        if (diff <= 0 && countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    tick();
    countdownTimer = setInterval(tick, 1000);
}

watch(() => store.expiresAt, (val) => {
    if (val) startCountdown(val);
});

watch(() => props.table.expires_at, (val) => {
    if (val && phase.value === 'betting') startCountdown(val);
}, { immediate: true });

onMounted(() => {
    store.subscribe(props.table.id);
    if (props.table.expires_at && props.table.phase === 'betting') {
        startCountdown(props.table.expires_at);
    }
});

onBeforeUnmount(() => {
    store.unsubscribe();
    if (countdownTimer) clearInterval(countdownTimer);
});

function placeBet(betType: string, numbers: number[]) {
    router.post(
        route('casino.roulette.bet', props.table.id),
        { bet_type: betType, numbers, amount: bet.value },
        { preserveScroll: true, preserveState: false },
    );
}

function formatAmount(v: number): string {
    return props.table.currency === 'akzar_cash'
        ? `A${v.toFixed(2)}`
        : `${v.toLocaleString()} bbl`;
}

const balance = computed(() =>
    props.table.currency === 'akzar_cash'
        ? Number(props.state.player.akzar_cash)
        : props.state.player.oil_barrels,
);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Roulette" />

        <div class="mx-auto max-w-4xl px-4 py-6">
            <CasinoNav current-page="roulette" />

            <div class="mt-6 text-center">
                <h1 class="text-xl font-bold text-amber-400">Roulette</h1>
                <p class="text-xs text-zinc-500">European single-zero &middot; {{ formatAmount(Number(table.min_bet)) }} &ndash; {{ formatAmount(Number(table.max_bet)) }}</p>
            </div>

            <!-- Status bar -->
            <div class="mt-4 flex items-center justify-between rounded-lg border border-zinc-700/50 bg-zinc-800/40 px-4 py-2">
                <div class="text-sm">
                    <span v-if="isBetting" class="text-green-400">
                        Bets Open &mdash; {{ countdown }}s remaining
                    </span>
                    <span v-else-if="phase === 'spinning'" class="animate-pulse text-amber-400">
                        Spinning...
                    </span>
                    <span v-else-if="phase === 'resolved' && lastResult" :class="resultColorClass(lastResult.number)">
                        Result: {{ lastResult.number }} ({{ lastResult.color }})
                    </span>
                    <span v-else class="text-zinc-500">
                        Waiting for bets...
                    </span>
                </div>
                <div class="text-xs text-zinc-500">
                    Balance: {{ formatAmount(balance) }}
                </div>
            </div>

            <!-- Error -->
            <div v-if="rouletteError" class="mt-3 rounded bg-red-900/30 px-3 py-2 text-sm text-red-400">
                {{ rouletteError }}
            </div>

            <!-- Result payouts -->
            <div v-if="lastResult && lastResult.payouts.length > 0" class="mt-3 rounded bg-zinc-800/60 border border-zinc-700 p-3">
                <h3 class="text-xs font-semibold text-zinc-400 mb-1">Payouts</h3>
                <div v-for="p in lastResult.payouts" :key="p.player_id" class="text-sm">
                    <span :class="p.net > 0 ? 'text-green-400' : 'text-red-400'">
                        {{ p.net > 0 ? '+' : '' }}{{ formatAmount(p.net) }}
                    </span>
                </div>
            </div>

            <!-- Bet controls -->
            <div class="mt-4 rounded-xl border border-zinc-700 bg-zinc-800/60 p-4">
                <div class="mb-3">
                    <ChipSelector
                        v-model="bet"
                        :currency="table.currency as 'akzar_cash' | 'oil_barrels'"
                        :min="Number(table.min_bet)"
                        :max="Number(table.max_bet)"
                    />
                </div>

                <RouletteBoard @bet="placeBet" />

                <!-- My bets this round -->
                <div v-if="table.my_bets.length > 0" class="mt-4 border-t border-zinc-700 pt-3">
                    <h3 class="text-xs font-semibold text-zinc-400 mb-1">Your Bets</h3>
                    <div class="space-y-1">
                        <div v-for="b in table.my_bets" :key="b.id" class="flex justify-between text-xs">
                            <span class="text-zinc-300">
                                {{ b.bet_type }}
                                <span v-if="b.numbers.length > 0" class="text-zinc-500">({{ b.numbers.join(', ') }})</span>
                            </span>
                            <span class="text-amber-400">{{ formatAmount(b.amount) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent bets from others (via WebSocket) -->
            <div v-if="store.recentBets.length > 0" class="mt-4 rounded-lg border border-zinc-800 bg-zinc-900/40 p-3">
                <h3 class="text-xs font-semibold text-zinc-500 mb-1">Live Bets</h3>
                <div v-for="(rb, i) in store.recentBets.slice(-5)" :key="i" class="text-xs text-zinc-400">
                    <span class="text-zinc-300">{{ rb.username }}</span> bet {{ formatAmount(rb.amount) }} on {{ rb.bet_type }}
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
