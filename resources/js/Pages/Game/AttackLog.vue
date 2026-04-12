<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

// Discriminated union — `kind` drives the renderer. Using a `kind`
// field instead of TS object-literal casts inside the template is a
// project convention (see MEMORY.md: Vue templates reject TS casts).
interface BaseEntry {
    id: string;
    source_id: number;
    outcome: string;
    cash_stolen: number;
    created_at: string;
    attacker_username: string;
    attacker_player_id: number;
    device_key: string | null;
    siphoned_barrels: number;
    rig_broken: boolean;
}
interface AttackEntry extends BaseEntry { kind: 'attack' }
interface SabotageEntry extends BaseEntry { kind: 'sabotage' }
type LogEntry = AttackEntry | SabotageEntry;

const props = defineProps<{
    owns_attack_log: boolean;
    attacks: LogEntry[];
}>();

const raidEntries = computed(() => props.attacks.filter((a): a is AttackEntry => a.kind === 'attack'));
const sabotageEntries = computed(() => props.attacks.filter((a): a is SabotageEntry => a.kind === 'sabotage'));

const totalStolen = computed(() =>
    raidEntries.value.reduce((sum, a) => sum + (a.outcome === 'success' ? a.cash_stolen : 0), 0),
);

const totalSiphoned = computed(() =>
    sabotageEntries.value.reduce((sum, s) => sum + s.siphoned_barrels, 0),
);

const rigsWrecked = computed(() =>
    sabotageEntries.value.filter((s) => s.rig_broken).length,
);

const successCount = computed(() =>
    raidEntries.value.filter((a) => a.outcome === 'success').length,
);

const failureCount = computed(() =>
    raidEntries.value.filter((a) => a.outcome === 'failure').length,
);

function formatTimestamp(iso: string): string {
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function deviceLabel(key: string | null): string {
    if (!key) return 'Device';
    return key
        .split('_')
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
}

function sabotageOutcomeLabel(outcome: string): string {
    switch (outcome) {
        case 'drill_broken_and_siphoned':
            return 'Rig wrecked + siphoned';
        case 'drill_broken':
            return 'Rig wrecked';
        case 'detected':
            return 'Tripwire caught';
        case 'fizzled':
            return 'Fizzled';
        default:
            return outcome;
    }
}

function sabotageBadgeClass(entry: SabotageEntry): string {
    if (entry.rig_broken) {
        return 'bg-rose-950 text-rose-300 border border-rose-800';
    }
    if (entry.outcome === 'detected') {
        return 'bg-emerald-950 text-emerald-300 border border-emerald-800';
    }
    return 'bg-amber-950 text-amber-300 border border-amber-800';
}
</script>

<template>
    <Head title="Attack Log — Clash Wars" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Attack Log
            </h2>
        </template>

        <div class="py-4 sm:py-8">
            <div class="max-w-4xl mx-auto px-3 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">
                <!-- Locked state -->
                <div
                    v-if="!owns_attack_log"
                    class="bg-zinc-900 border-2 border-zinc-800 rounded-lg p-6 sm:p-12 text-center font-mono"
                >
                    <div class="inline-flex mb-6 text-zinc-600">
                        <svg viewBox="0 0 48 48" class="w-20 h-20" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="10" y="14" width="28" height="26" rx="2" />
                            <path d="M20 14 L20 8 A4 4 0 0 1 28 8 L28 14" />
                            <path d="M16 24 L32 24 M16 30 L32 30 M16 36 L26 36" />
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold text-zinc-100 mb-3">Attack log locked</h3>
                    <p class="text-zinc-400 max-w-xl mx-auto mb-6">
                        Buy the <span class="text-amber-400">Counter-Intel Dossier</span> at any
                        <span class="text-emerald-400">Fortification Post</span>. It's the most
                        expensive item there — 400 oil barrels for a locked archive and a paid
                        informant network. Once installed, every raid on your base shows up here:
                        who hit you, when, and how much they took.
                    </p>
                    <Link
                        :href="route('map.show')"
                        class="inline-flex items-center rounded-md border border-zinc-700 px-4 py-2 font-mono text-sm uppercase tracking-wider text-zinc-300 hover:border-amber-400 hover:text-amber-400 transition"
                    >
                        Back to map
                    </Link>
                </div>

                <!-- Unlocked -->
                <template v-else>
                    <!-- Summary bar -->
                    <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-3 sm:p-4 grid grid-cols-2 md:grid-cols-6 gap-3 sm:gap-4 text-sm font-mono">
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Events</div>
                            <div class="text-amber-400 text-lg">{{ attacks.length }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Successful raids</div>
                            <div class="text-rose-400 text-lg">{{ successCount }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Repelled</div>
                            <div class="text-emerald-400 text-lg">{{ failureCount }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Total stolen</div>
                            <div class="text-rose-400 text-lg">A{{ totalStolen.toFixed(2) }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Rigs wrecked</div>
                            <div class="text-rose-400 text-lg">{{ rigsWrecked }}</div>
                        </div>
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Barrels siphoned</div>
                            <div class="text-amber-400 text-lg">{{ totalSiphoned }}</div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div
                        v-if="attacks.length === 0"
                        class="bg-zinc-900 border border-zinc-800 rounded-lg p-6 sm:p-12 text-center font-mono text-zinc-500"
                    >
                        No raids or sabotage on your base yet. Enjoy the quiet — it won't last.
                    </div>

                    <!-- Log -->
                    <div
                        v-else
                        class="bg-zinc-900 border border-zinc-800 rounded-lg divide-y divide-zinc-800 font-mono"
                    >
                        <div
                            v-for="entry in attacks"
                            :key="entry.id"
                            class="p-3 sm:p-4 flex items-start justify-between gap-3 sm:gap-4"
                        >
                            <!-- RAID ENTRY -->
                            <template v-if="entry.kind === 'attack'">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <span
                                            class="text-xs uppercase tracking-widest px-2 py-0.5 rounded"
                                            :class="entry.outcome === 'success'
                                                ? 'bg-rose-950 text-rose-300 border border-rose-800'
                                                : 'bg-emerald-950 text-emerald-300 border border-emerald-800'"
                                        >
                                            {{ entry.outcome === 'success' ? 'Breached' : 'Repelled' }}
                                        </span>
                                        <span class="text-zinc-100 font-bold break-words">{{ entry.attacker_username }}</span>
                                        <span class="text-zinc-500 text-xs">attacker</span>
                                    </div>
                                    <div class="text-zinc-500 text-xs mt-1">
                                        {{ formatTimestamp(entry.created_at) }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        v-if="entry.outcome === 'success' && entry.cash_stolen > 0"
                                        class="text-rose-400 text-sm"
                                    >
                                        -A{{ entry.cash_stolen.toFixed(2) }}
                                    </div>
                                    <div v-else class="text-zinc-600 text-xs italic">no loss</div>
                                </div>
                            </template>

                            <!-- SABOTAGE ENTRY -->
                            <template v-else>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-baseline gap-2 flex-wrap">
                                        <span
                                            class="text-xs uppercase tracking-widest px-2 py-0.5 rounded"
                                            :class="sabotageBadgeClass(entry)"
                                        >
                                            {{ sabotageOutcomeLabel(entry.outcome) }}
                                        </span>
                                        <span class="text-zinc-100 font-bold break-words">{{ entry.attacker_username }}</span>
                                        <span class="text-zinc-500 text-xs">planted {{ deviceLabel(entry.device_key) }}</span>
                                    </div>
                                    <div class="text-zinc-500 text-xs mt-1">
                                        {{ formatTimestamp(entry.created_at) }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        v-if="entry.siphoned_barrels > 0"
                                        class="text-rose-400 text-sm"
                                    >
                                        -{{ entry.siphoned_barrels }} barrels
                                    </div>
                                    <div v-else-if="entry.rig_broken" class="text-rose-400 text-xs uppercase tracking-widest">rig wrecked</div>
                                    <div v-else class="text-zinc-600 text-xs italic">rig safe</div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
