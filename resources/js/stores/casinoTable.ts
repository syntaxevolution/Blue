import { defineStore } from 'pinia';
import { ref } from 'vue';

interface RouletteResultEvent {
    table_id: number;
    round_number: number;
    number: number;
    color: string;
    payouts: Array<{ player_id: number; amount: number; net: number }>;
}

interface BettingWindowEvent {
    table_id: number;
    round_number: number;
    expires_at: string;
}

interface BetPlacedEvent {
    table_id: number;
    username: string;
    bet_type: string;
    amount: number;
}

export const useCasinoTableStore = defineStore('casinoTable', () => {
    const tableId = ref<number | null>(null);
    const phase = ref<string>('idle');
    const roundNumber = ref(0);
    const expiresAt = ref<string | null>(null);
    const lastResult = ref<RouletteResultEvent | null>(null);
    const recentBets = ref<BetPlacedEvent[]>([]);
    const totalBets = ref(0);

    let echoChannel: any = null;

    function subscribe(id: number) {
        unsubscribe();
        tableId.value = id;

        if (typeof window !== 'undefined' && (window as any).Echo) {
            echoChannel = (window as any).Echo.join(`casino.table.${id}`)
                .listen('.BettingWindowOpened', (e: BettingWindowEvent) => {
                    phase.value = 'betting';
                    roundNumber.value = e.round_number;
                    expiresAt.value = e.expires_at;
                    lastResult.value = null;
                    recentBets.value = [];
                })
                .listen('.RouletteResult', (e: RouletteResultEvent) => {
                    phase.value = 'resolved';
                    lastResult.value = e;
                    expiresAt.value = null;
                })
                .listen('.BetPlaced', (e: BetPlacedEvent) => {
                    recentBets.value.push(e);
                    totalBets.value++;
                });
        }
    }

    function unsubscribe() {
        if (echoChannel && tableId.value !== null) {
            (window as any).Echo?.leave(`casino.table.${tableId.value}`);
            echoChannel = null;
        }
        tableId.value = null;
        phase.value = 'idle';
        lastResult.value = null;
        recentBets.value = [];
    }

    function reset() {
        phase.value = 'idle';
        lastResult.value = null;
        recentBets.value = [];
        totalBets.value = 0;
    }

    return {
        tableId,
        phase,
        roundNumber,
        expiresAt,
        lastResult,
        recentBets,
        totalBets,
        subscribe,
        unsubscribe,
        reset,
    };
});
