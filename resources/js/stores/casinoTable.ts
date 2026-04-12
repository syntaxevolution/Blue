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

interface BlackjackDealtEvent {
    table_id: number;
    round_number: number;
    player_card_counts: number[];
    dealer_up_card: { rank: string; suit: string };
}

interface BlackjackActionEvent {
    table_id: number;
    seat: number;
    action: string;
    hand_total: number;
}

interface BlackjackDealerTurnEvent {
    table_id: number;
    cards: Array<{ rank: string; suit: string; display: string }>;
    total: number;
    bust: boolean;
}

interface HoldemCommunityEvent {
    table_id: number;
    phase: string;
    cards: Array<{ rank: string; suit: string; display: string }>;
}

interface HoldemPlayerActionEvent {
    table_id: number;
    seat: number;
    action: string;
    amount: number;
    pot_total: number;
}

interface HoldemShowdownEvent {
    table_id: number;
    results: Array<{ player_id: number; amount: number; hand: string | null }>;
    community: Array<{ rank: string; suit: string; display: string }>;
}

interface TableChatEvent {
    table_id: number;
    username: string;
    message: string;
    timestamp: string;
}

interface PlayerSeatEvent {
    table_id: number;
    username: string;
    seat?: number;
}

export const useCasinoTableStore = defineStore('casinoTable', () => {
    const tableId = ref<number | null>(null);
    const phase = ref<string>('idle');
    const roundNumber = ref(0);
    const expiresAt = ref<string | null>(null);
    const lastResult = ref<RouletteResultEvent | null>(null);
    const recentBets = ref<BetPlacedEvent[]>([]);
    const totalBets = ref(0);

    // Blackjack live state
    const blackjackLastAction = ref<BlackjackActionEvent | null>(null);
    const blackjackDealerResult = ref<BlackjackDealerTurnEvent | null>(null);

    // Hold'em live state
    const holdemLastAction = ref<HoldemPlayerActionEvent | null>(null);
    const holdemCommunity = ref<HoldemCommunityEvent | null>(null);
    const holdemShowdown = ref<HoldemShowdownEvent | null>(null);
    const holdemHoleCards = ref<Array<{ rank: string; suit: string; display: string }> | null>(null);

    // Chat
    const chatMessages = ref<TableChatEvent[]>([]);

    // Seat changes
    const seatChanges = ref<PlayerSeatEvent[]>([]);

    let echoChannel: any = null;
    let userChannel: any = null;

    function subscribe(id: number, userId: number | null = null) {
        unsubscribe();
        tableId.value = id;

        if (typeof window === 'undefined' || !(window as any).Echo) {
            return;
        }

        echoChannel = (window as any).Echo.join(`casino.table.${id}`)
            // Roulette
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
            })
            // Blackjack
            .listen('.BlackjackHandDealt', (_e: BlackjackDealtEvent) => {
                phase.value = 'dealing';
            })
            .listen('.BlackjackPlayerAction', (e: BlackjackActionEvent) => {
                blackjackLastAction.value = e;
            })
            .listen('.BlackjackDealerTurn', (e: BlackjackDealerTurnEvent) => {
                blackjackDealerResult.value = e;
                phase.value = 'dealer_turn';
            })
            .listen('.BlackjackPayout', () => {
                phase.value = 'payout';
            })
            // Hold'em
            .listen('.HoldemPlayerAction', (e: HoldemPlayerActionEvent) => {
                holdemLastAction.value = e;
            })
            .listen('.HoldemCommunityCards', (e: HoldemCommunityEvent) => {
                holdemCommunity.value = e;
                phase.value = e.phase;
            })
            .listen('.HoldemShowdown', (e: HoldemShowdownEvent) => {
                holdemShowdown.value = e;
                phase.value = 'showdown';
            })
            // Chat
            .listen('.TableChatMessage', (e: TableChatEvent) => {
                chatMessages.value.push(e);
                if (chatMessages.value.length > 100) {
                    chatMessages.value.shift();
                }
            })
            // Seat changes
            .listen('.PlayerJoinedTable', (e: PlayerSeatEvent) => {
                seatChanges.value.push(e);
            })
            .listen('.PlayerLeftTable', (e: PlayerSeatEvent) => {
                seatChanges.value.push(e);
            });

        // Hole cards go on the user's private channel.
        if (userId !== null) {
            userChannel = (window as any).Echo.private(`App.Models.User.${userId}`)
                .listen('.HoldemHoleCards', (e: { table_id: number; cards: any[] }) => {
                    if (e.table_id === id) {
                        holdemHoleCards.value = e.cards;
                    }
                });
        }
    }

    function unsubscribe() {
        if (echoChannel && tableId.value !== null && typeof window !== 'undefined') {
            (window as any).Echo?.leave(`casino.table.${tableId.value}`);
            echoChannel = null;
        }
        // NOTE: do NOT leave the user private channel here — it is shared with
        // useNotifications and other app-wide listeners. Stop listening for
        // the HoldemHoleCards event specifically.
        if (userChannel) {
            userChannel.stopListening('.HoldemHoleCards');
            userChannel = null;
        }
        tableId.value = null;
        phase.value = 'idle';
        roundNumber.value = 0;
        expiresAt.value = null;
        lastResult.value = null;
        recentBets.value = [];
        blackjackLastAction.value = null;
        blackjackDealerResult.value = null;
        holdemLastAction.value = null;
        holdemCommunity.value = null;
        holdemShowdown.value = null;
        holdemHoleCards.value = null;
        chatMessages.value = [];
        seatChanges.value = [];
    }

    function reset() {
        phase.value = 'idle';
        lastResult.value = null;
        recentBets.value = [];
        totalBets.value = 0;
        blackjackLastAction.value = null;
        blackjackDealerResult.value = null;
        holdemLastAction.value = null;
        holdemCommunity.value = null;
        holdemShowdown.value = null;
        holdemHoleCards.value = null;
    }

    return {
        tableId,
        phase,
        roundNumber,
        expiresAt,
        lastResult,
        recentBets,
        totalBets,
        blackjackLastAction,
        blackjackDealerResult,
        holdemLastAction,
        holdemCommunity,
        holdemShowdown,
        holdemHoleCards,
        chatMessages,
        seatChanges,
        subscribe,
        unsubscribe,
        reset,
    };
});
