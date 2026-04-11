<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AttackEntry {
    id: number;
    outcome: string;
    cash_stolen: number;
    created_at: string;
    attacker_username: string;
    attacker_player_id: number;
}

const props = defineProps<{
    owns_attack_log: boolean;
    attacks: AttackEntry[];
}>();

const totalStolen = computed(() =>
    props.attacks.reduce((sum, a) => sum + (a.outcome === 'success' ? a.cash_stolen : 0), 0),
);

const successCount = computed(() =>
    props.attacks.filter((a) => a.outcome === 'success').length,
);

const failureCount = computed(() =>
    props.attacks.filter((a) => a.outcome === 'failure').length,
);

function formatTimestamp(iso: string): string {
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
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

        <div class="py-8">
            <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Locked state -->
                <div
                    v-if="!owns_attack_log"
                    class="bg-zinc-900 border-2 border-zinc-800 rounded-lg p-12 text-center font-mono"
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
                    <div class="bg-zinc-900 border border-zinc-800 rounded-lg p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm font-mono">
                        <div>
                            <div class="text-zinc-500 text-xs uppercase">Raids recorded</div>
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
                    </div>

                    <!-- Empty state -->
                    <div
                        v-if="attacks.length === 0"
                        class="bg-zinc-900 border border-zinc-800 rounded-lg p-12 text-center font-mono text-zinc-500"
                    >
                        No raids on your base yet. Enjoy the quiet — it won't last.
                    </div>

                    <!-- Log -->
                    <div
                        v-else
                        class="bg-zinc-900 border border-zinc-800 rounded-lg divide-y divide-zinc-800 font-mono"
                    >
                        <div
                            v-for="attack in attacks"
                            :key="attack.id"
                            class="p-4 flex items-start justify-between gap-4"
                        >
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline gap-2 flex-wrap">
                                    <span
                                        class="text-xs uppercase tracking-widest px-2 py-0.5 rounded"
                                        :class="attack.outcome === 'success'
                                            ? 'bg-rose-950 text-rose-300 border border-rose-800'
                                            : 'bg-emerald-950 text-emerald-300 border border-emerald-800'"
                                    >
                                        {{ attack.outcome === 'success' ? 'Breached' : 'Repelled' }}
                                    </span>
                                    <span class="text-zinc-100 font-bold">{{ attack.attacker_username }}</span>
                                    <span class="text-zinc-500 text-xs">attacker</span>
                                </div>
                                <div class="text-zinc-500 text-xs mt-1">
                                    {{ formatTimestamp(attack.created_at) }}
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div
                                    v-if="attack.outcome === 'success' && attack.cash_stolen > 0"
                                    class="text-rose-400 text-sm"
                                >
                                    -A{{ attack.cash_stolen.toFixed(2) }}
                                </div>
                                <div v-else class="text-zinc-600 text-xs italic">no loss</div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
