<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

interface LeaderboardRow {
    rank: number;
    player_id: number;
    username: string;
    mdn_tag: string | null;
    value: number;
}

interface LeaderboardBoard {
    top: LeaderboardRow[];
    viewer: LeaderboardRow | null;
}

interface Leaderboards {
    akzar_cash: LeaderboardBoard;
    stored_oil: LeaderboardBoard;
    stat_total: LeaderboardBoard;
}

const props = defineProps<{
    startingCash: string;
    dailyRegen: number;
    bankCap: number;
    immunityHours: number;
    leaderboards: Leaderboards;
    currentPlayerId: number | null;
}>();

function formatCash(v: number): string {
    return `A${v.toFixed(2)}`;
}

function formatBarrels(v: number): string {
    return `${v.toLocaleString()}`;
}

function formatStats(v: number): string {
    return `${v}`;
}

const boards = [
    {
        key: 'akzar_cash' as const,
        title: 'Akzar Cash',
        subtitle: 'Richest on Akzar',
        board: props.leaderboards.akzar_cash,
        format: formatCash,
    },
    {
        key: 'stored_oil' as const,
        title: 'Stored Oil',
        subtitle: 'Biggest barrel hoards',
        board: props.leaderboards.stored_oil,
        format: formatBarrels,
    },
    {
        key: 'stat_total' as const,
        title: 'Stat Total',
        subtitle: 'Str + Fort + Stealth + Sec',
        board: props.leaderboards.stat_total,
        format: formatStats,
    },
];
</script>

<template>
    <Head title="Dashboard — Clash Wars" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Dashboard
            </h2>
        </template>

        <div class="py-6 sm:py-12">
            <div class="mx-auto max-w-4xl px-3 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">
                <div class="rounded-lg border border-zinc-800 bg-zinc-900 p-5 sm:p-8">
                    <div class="font-mono text-xs uppercase tracking-widest text-zinc-500 mb-2">
                        Welcome to Akzar
                    </div>
                    <h3 class="font-mono text-2xl sm:text-3xl font-black uppercase text-zinc-100 mb-4 break-words">
                        The dust never settles.
                    </h3>
                    <p class="text-zinc-400 leading-relaxed mb-6 max-w-2xl text-sm sm:text-base">
                        You've landed on a world of cracked earth, rusted rigs, and cash
                        stashed behind reinforced doors. Your base is already claimed.
                        The oil fields are waiting. Head to the map to start drilling,
                        scouting, and picking fights.
                    </p>
                    <Link
                        :href="route('map.show')"
                        class="inline-flex items-center rounded-md bg-amber-500 px-5 sm:px-6 py-3 font-mono text-sm sm:text-base font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition shadow-xl shadow-amber-900/30"
                    >
                        Enter the map →
                    </Link>
                </div>

                <div class="grid gap-4 md:grid-cols-3 text-sm font-mono">
                    <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4">
                        <div class="text-xs uppercase tracking-widest text-zinc-500 mb-1">
                            Starting cash
                        </div>
                        <div class="text-xl text-amber-400">A{{ startingCash }}</div>
                    </div>
                    <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4">
                        <div class="text-xs uppercase tracking-widest text-zinc-500 mb-1">
                            Move regen
                        </div>
                        <div class="text-xl text-zinc-100">
                            {{ dailyRegen }} / day
                        </div>
                        <div class="text-[10px] uppercase tracking-widest text-zinc-500 mt-1">
                            bank cap {{ bankCap }}
                        </div>
                    </div>
                    <div class="rounded border border-zinc-800 bg-zinc-900/60 p-4">
                        <div class="text-xs uppercase tracking-widest text-zinc-500 mb-1">
                            New player immunity
                        </div>
                        <div class="text-xl text-zinc-100">{{ immunityHours }} hours</div>
                    </div>
                </div>

                <!-- Leaderboards -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div
                        v-for="board in boards"
                        :key="board.key"
                        class="rounded-lg border border-zinc-800 bg-zinc-900 p-4 sm:p-5 font-mono"
                    >
                        <div class="flex items-baseline justify-between mb-3">
                            <div>
                                <div class="text-amber-400 text-sm sm:text-base font-bold uppercase tracking-widest">{{ board.title }}</div>
                                <div class="text-zinc-500 text-[10px] uppercase tracking-widest mt-0.5">{{ board.subtitle }}</div>
                            </div>
                            <div class="text-zinc-600 text-[10px] uppercase tracking-widest">Top {{ board.board.top.length }}</div>
                        </div>
                        <div v-if="board.board.top.length === 0" class="text-zinc-500 italic text-xs text-center py-3">
                            No players yet.
                        </div>
                        <template v-else>
                            <ol class="space-y-1.5">
                                <li
                                    v-for="row in board.board.top"
                                    :key="row.player_id"
                                    class="flex items-center gap-2 sm:gap-3 rounded px-2 py-1.5 text-xs sm:text-sm"
                                    :class="row.player_id === currentPlayerId
                                        ? 'bg-amber-500/10 border border-amber-500/40 text-amber-100'
                                        : 'bg-zinc-950/40 border border-transparent text-zinc-300'"
                                >
                                    <span
                                        class="w-6 text-right font-bold tabular-nums"
                                        :class="row.rank === 1 ? 'text-amber-400' : 'text-zinc-500'"
                                    >{{ row.rank }}</span>
                                    <span class="flex-1 min-w-0 truncate">
                                        {{ row.username }}
                                        <span v-if="row.mdn_tag" class="text-amber-400/70 ml-1">[{{ row.mdn_tag }}]</span>
                                    </span>
                                    <span class="shrink-0 tabular-nums">{{ board.format(row.value) }}</span>
                                </li>
                            </ol>
                            <!-- Viewer row: only rendered when the current player is NOT in the top-N -->
                            <div v-if="board.board.viewer" class="mt-2 pt-2 border-t border-dashed border-zinc-800">
                                <div class="text-[10px] uppercase tracking-widest text-zinc-600 mb-1">Your rank</div>
                                <div class="flex items-center gap-2 sm:gap-3 rounded px-2 py-1.5 text-xs sm:text-sm bg-amber-500/10 border border-amber-500/40 text-amber-100">
                                    <span class="w-6 text-right font-bold tabular-nums text-amber-300">{{ board.board.viewer.rank }}</span>
                                    <span class="flex-1 min-w-0 truncate">
                                        {{ board.board.viewer.username }}
                                        <span v-if="board.board.viewer.mdn_tag" class="text-amber-400/70 ml-1">[{{ board.board.viewer.mdn_tag }}]</span>
                                    </span>
                                    <span class="shrink-0 tabular-nums">{{ board.format(board.board.viewer.value) }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
