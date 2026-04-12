<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CasinoNav from '@/Components/Casino/CasinoNav.vue';
import CurrencyToggle from '@/Components/Casino/CurrencyToggle.vue';
import ChipSelector from '@/Components/Casino/ChipSelector.vue';
import SlotReel from '@/Components/Casino/SlotReel.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface PlayerState {
    oil_barrels: number;
    akzar_cash: number;
}

interface SpinResult {
    reels: string[];
    payout: number;
    net: number;
    balance: number;
    multiplier: number;
    win_line: string | null;
}

interface MapState {
    player: PlayerState;
}

const props = defineProps<{
    state: MapState;
    casino_session: { id: number; expires_at: string } | null;
}>();

const page = usePage();
const errors = computed(() => (page.props.errors as Record<string, string>) ?? {});
const flash = computed(() => (page.props.flash as Record<string, unknown>) ?? {});
const spinResult = computed(() => flash.value.spin_result as SpinResult | undefined);
const slotError = computed(() => errors.value.slots ?? null);

const currency = ref<'akzar_cash' | 'oil_barrels'>('oil_barrels');
const bet = ref(10);
const spinning = ref(false);
const lastReels = ref<string[]>([]);
const lastResult = ref<SpinResult | null>(null);

const betMin = computed(() =>
    currency.value === 'akzar_cash' ? 0.10 : 10,
);
const betMax = computed(() =>
    currency.value === 'akzar_cash' ? 500 : 50000,
);

const balance = computed(() =>
    currency.value === 'akzar_cash'
        ? Number(props.state.player.akzar_cash)
        : props.state.player.oil_barrels,
);

const canSpin = computed(() =>
    !spinning.value && bet.value >= betMin.value && bet.value <= betMax.value && balance.value >= bet.value,
);

watch(spinResult, (result) => {
    if (result) {
        spinning.value = false;
        lastReels.value = result.reels;
        lastResult.value = result;
    }
});

watch(currency, () => {
    bet.value = currency.value === 'akzar_cash' ? 0.10 : 10;
    lastResult.value = null;
    lastReels.value = [];
});

function spin() {
    if (!canSpin.value) return;
    spinning.value = true;
    lastResult.value = null;

    router.post(
        route('casino.slots.spin'),
        { currency: currency.value, bet: bet.value },
        { preserveScroll: true, preserveState: true },
    );
}

function formatAmount(v: number): string {
    if (currency.value === 'akzar_cash') {
        return `A${v.toFixed(2)}`;
    }
    return `${v.toLocaleString()} bbl`;
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Slot Machines" />

        <div class="mx-auto max-w-2xl px-4 py-6">
            <CasinoNav current-page="slots" />

            <div class="mt-6 text-center">
                <h1 class="text-xl font-bold text-amber-400">Slot Machines</h1>
                <p class="mt-1 text-xs text-zinc-500">3-reel slots &mdash; match symbols to win</p>
            </div>

            <!-- Balance display -->
            <div class="mt-4 flex justify-center gap-6 text-sm">
                <div>
                    <span class="text-zinc-500">Cash:</span>
                    <span class="ml-1 font-semibold text-amber-400">A{{ Number(state.player.akzar_cash).toFixed(2) }}</span>
                </div>
                <div>
                    <span class="text-zinc-500">Oil:</span>
                    <span class="ml-1 font-semibold text-amber-400">{{ state.player.oil_barrels.toLocaleString() }} bbl</span>
                </div>
            </div>

            <!-- Slot machine -->
            <div class="mt-6 rounded-xl border border-zinc-700 bg-zinc-800/60 p-6">
                <!-- Reels -->
                <div class="flex items-center justify-center gap-3">
                    <SlotReel
                        :symbol="lastReels[0] ?? null"
                        :spinning="spinning"
                        :delay="200"
                    />
                    <SlotReel
                        :symbol="lastReels[1] ?? null"
                        :spinning="spinning"
                        :delay="400"
                    />
                    <SlotReel
                        :symbol="lastReels[2] ?? null"
                        :spinning="spinning"
                        :delay="600"
                    />
                </div>

                <!-- Result display -->
                <div class="mt-4 h-12 text-center">
                    <div v-if="slotError" class="rounded bg-red-900/30 px-3 py-2 text-sm text-red-400">
                        {{ slotError }}
                    </div>
                    <div v-else-if="lastResult && lastResult.win_line" class="animate-pulse">
                        <p class="text-lg font-bold text-green-400">
                            WIN! {{ formatAmount(lastResult.payout) }}
                        </p>
                        <p class="text-xs text-green-500">{{ lastResult.multiplier }}x multiplier</p>
                    </div>
                    <div v-else-if="lastResult && !lastResult.win_line">
                        <p class="text-sm text-zinc-500">No match. Try again!</p>
                    </div>
                </div>

                <!-- Controls -->
                <div class="mt-4 space-y-4">
                    <div class="flex items-center justify-center">
                        <CurrencyToggle v-model="currency" />
                    </div>

                    <ChipSelector
                        v-model="bet"
                        :currency="currency"
                        :min="betMin"
                        :max="betMax"
                    />

                    <div class="flex justify-center">
                        <button
                            :disabled="!canSpin"
                            class="rounded-lg px-10 py-3 text-lg font-bold transition-all"
                            :class="canSpin
                                ? 'bg-amber-600 text-white shadow-lg shadow-amber-600/20 hover:bg-amber-500 active:scale-95'
                                : 'cursor-not-allowed bg-zinc-700 text-zinc-500'"
                            @click="spin"
                        >
                            {{ spinning ? 'Spinning...' : 'SPIN' }}
                        </button>
                    </div>

                    <p class="text-center text-xs text-zinc-600">
                        Bet: {{ formatAmount(bet) }} &middot; Balance: {{ formatAmount(balance) }}
                    </p>
                </div>
            </div>

            <!-- Pay table -->
            <div class="mt-6 rounded-lg border border-zinc-800 bg-zinc-900/40 p-4">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Pay Table</h3>
                <div class="grid grid-cols-2 gap-1 text-xs">
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">🛢️ 🛢️ 🛢️ AKZAR</span>
                        <span class="font-semibold text-amber-400">500x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">💎 💎 💎 Diamond</span>
                        <span class="font-semibold text-amber-400">250x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">7 7 7 Triple Seven</span>
                        <span class="font-semibold text-amber-400">150x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">3x 3x 3x Triple BAR</span>
                        <span class="font-semibold text-amber-400">100x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">2x 2x 2x Double BAR</span>
                        <span class="font-semibold text-amber-400">60x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">BAR BAR BAR</span>
                        <span class="font-semibold text-amber-400">25x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">🍒 🍒 🍒 Cherry</span>
                        <span class="font-semibold text-amber-400">10x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">Any 3 BARs (mixed)</span>
                        <span class="font-semibold text-amber-400">2x</span>
                    </div>
                    <div class="flex justify-between rounded bg-zinc-800/40 px-2 py-1">
                        <span class="text-zinc-400">🍒 🍒 Two Cherry</span>
                        <span class="font-semibold text-amber-400">1x</span>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
