<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface MdnRow {
    id: number;
    name: string;
    tag: string;
    member_count: number;
    motto: string | null;
    leader_player_id: number;
}

interface Member {
    player_id: number;
    user_name: string;
    role: string;
    joined_at: string | null;
    akzar_cash: number;
}

interface AllianceRow {
    id: number;
    other_mdn: { id: number; name: string; tag: string } | null;
    declared_at: string | null;
}

interface JournalEntry {
    id: number;
    author_player_id: number;
    tile_id: number | null;
    body: string;
    helpful_count: number;
    unhelpful_count: number;
    created_at: string | null;
}

const props = defineProps<{
    mdn: MdnRow;
    members: Member[];
    alliances: AllianceRow[];
    journal: JournalEntry[];
    player_id: number;
    is_member: boolean;
    is_leader: boolean;
    own_mdn_id: number | null;
}>();

const page = usePage();
const flash = computed(() => page.props.flash as { status?: string } | undefined);
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const tab = ref<'members' | 'journal' | 'alliances'>('members');

const entryForm = useForm<{ body: string; tile_id: number | null }>({
    body: '',
    tile_id: null,
});

function join() {
    router.post(route('mdn.join', props.mdn.id));
}
function leave() {
    if (confirm('Leave this MDN? You will need 24h before offensive actions unlock.')) {
        router.post(route('mdn.leave', props.mdn.id));
    }
}
function disband() {
    if (confirm('Disband this MDN? This is irreversible and removes every member.')) {
        router.post(route('mdn.disband', props.mdn.id));
    }
}
function kick(playerId: number) {
    router.post(route('mdn.kick', { mdn: props.mdn.id, player: playerId }));
}
function promote(playerId: number, role: string) {
    router.post(route('mdn.promote', { mdn: props.mdn.id, player: playerId }), { role });
}
function vote(entryId: number, v: 'helpful' | 'unhelpful') {
    router.post(
        route('mdn.journal.vote', { mdn: props.mdn.id, entry: entryId }),
        { vote: v },
    );
}
function addEntry() {
    entryForm.post(route('mdn.journal.store', props.mdn.id), {
        onSuccess: () => entryForm.reset('body'),
    });
}
function declareAlliance() {
    const otherId = prompt('Enter the ID of the MDN you wish to ally with:');
    if (!otherId) return;
    router.post(route('mdn.alliances.store', props.mdn.id), {
        other_mdn_id: parseInt(otherId, 10),
    });
}
function revokeAlliance(allianceId: number) {
    if (confirm('Revoke this alliance?')) {
        router.delete(
            route('mdn.alliances.destroy', { mdn: props.mdn.id, alliance: allianceId }),
        );
    }
}
</script>

<template>
    <Head :title="`${mdn.name} [${mdn.tag}]`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-black uppercase tracking-widest text-amber-400">
                <span class="font-mono">[{{ mdn.tag }}]</span> {{ mdn.name }}
            </h2>
        </template>

        <div class="py-4 sm:py-8">
            <div class="mx-auto max-w-5xl space-y-4 sm:space-y-6 px-3 sm:px-6 lg:px-8">
                <div
                    v-if="flash?.status"
                    class="rounded border border-emerald-700 bg-emerald-900/40 px-4 py-2 text-sm text-emerald-200"
                >
                    {{ flash.status }}
                </div>
                <div
                    v-for="(err, k) in errors"
                    :key="k"
                    class="rounded border border-rose-700 bg-rose-900/40 px-4 py-2 text-sm text-rose-200"
                >
                    {{ err }}
                </div>

                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-xs uppercase tracking-widest text-zinc-500">
                            {{ mdn.member_count }} members
                        </div>
                        <p v-if="mdn.motto" class="mt-1 text-sm italic text-zinc-300 break-words">
                            “{{ mdn.motto }}”
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-if="!is_member && own_mdn_id === null"
                            type="button"
                            @click="join"
                            class="rounded bg-amber-500 px-3 py-2 font-mono text-xs font-bold uppercase tracking-widest text-zinc-950 hover:bg-amber-400"
                        >
                            Join
                        </button>
                        <button
                            v-if="is_member && !is_leader"
                            type="button"
                            @click="leave"
                            class="rounded bg-zinc-800 px-3 py-2 font-mono text-xs font-bold uppercase tracking-widest text-zinc-300 hover:bg-rose-800 hover:text-rose-100"
                        >
                            Leave
                        </button>
                        <button
                            v-if="is_leader"
                            type="button"
                            @click="disband"
                            class="rounded bg-rose-900 px-3 py-2 font-mono text-xs font-bold uppercase tracking-widest text-rose-100 hover:bg-rose-700"
                        >
                            Disband
                        </button>
                    </div>
                </div>

                <div role="tablist" aria-label="MDN sections" class="flex flex-wrap border-b border-zinc-800">
                    <button
                        id="mdn-tab-members"
                        type="button"
                        role="tab"
                        :aria-selected="tab === 'members'"
                        aria-controls="mdn-panel-members"
                        @click="tab = 'members'"
                        :class="[
                            'px-4 py-2 font-mono text-xs uppercase tracking-widest',
                            tab === 'members' ? 'border-b-2 border-amber-500 text-amber-400' : 'text-zinc-500',
                        ]"
                    >
                        Members
                    </button>
                    <button
                        id="mdn-tab-journal"
                        type="button"
                        role="tab"
                        :aria-selected="tab === 'journal'"
                        aria-controls="mdn-panel-journal"
                        @click="tab = 'journal'"
                        :class="[
                            'px-4 py-2 font-mono text-xs uppercase tracking-widest',
                            tab === 'journal' ? 'border-b-2 border-amber-500 text-amber-400' : 'text-zinc-500',
                        ]"
                    >
                        Journal ({{ journal.length }})
                    </button>
                    <button
                        id="mdn-tab-alliances"
                        type="button"
                        role="tab"
                        :aria-selected="tab === 'alliances'"
                        aria-controls="mdn-panel-alliances"
                        @click="tab = 'alliances'"
                        :class="[
                            'px-4 py-2 font-mono text-xs uppercase tracking-widest',
                            tab === 'alliances' ? 'border-b-2 border-amber-500 text-amber-400' : 'text-zinc-500',
                        ]"
                    >
                        Alliances ({{ alliances.length }})
                    </button>
                </div>

                <!-- Members tab -->
                <div
                    v-if="tab === 'members'"
                    id="mdn-panel-members"
                    role="tabpanel"
                    aria-labelledby="mdn-tab-members"
                >
                    <!-- Mobile: card list -->
                    <div class="space-y-2 sm:hidden">
                        <div
                            v-for="m in members"
                            :key="m.player_id"
                            class="rounded border border-zinc-800 bg-zinc-900/40 p-3"
                        >
                            <div class="flex items-baseline justify-between gap-2">
                                <div class="min-w-0 flex-1 text-base font-bold text-zinc-100 break-words">
                                    {{ m.user_name }}
                                </div>
                                <span class="font-mono text-[10px] uppercase tracking-widest text-amber-300">
                                    {{ m.role }}
                                </span>
                            </div>
                            <div class="mt-1 font-mono text-xs text-zinc-500">
                                A{{ m.akzar_cash.toFixed(2) }}
                            </div>
                            <div
                                v-if="is_leader && m.player_id !== player_id"
                                class="mt-3 flex gap-2"
                            >
                                <button
                                    type="button"
                                    @click="promote(m.player_id, m.role === 'officer' ? 'member' : 'officer')"
                                    class="tap-target flex-1 rounded border border-amber-700 bg-amber-950/40 px-3 py-2 text-xs font-mono uppercase tracking-widest text-amber-300 active:bg-amber-900/60"
                                >
                                    {{ m.role === 'officer' ? 'Demote' : 'Promote' }}
                                </button>
                                <button
                                    type="button"
                                    @click="kick(m.player_id)"
                                    class="tap-target flex-1 rounded border border-rose-700 bg-rose-950/40 px-3 py-2 text-xs font-mono uppercase tracking-widest text-rose-300 active:bg-rose-900/60"
                                >
                                    Kick
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Desktop: table -->
                    <div class="hidden overflow-x-auto rounded border border-zinc-800 sm:block">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-zinc-900 text-xs uppercase tracking-widest text-zinc-400">
                                <tr>
                                    <th class="px-4 py-2">Name</th>
                                    <th class="px-4 py-2">Role</th>
                                    <th class="px-4 py-2">Cash</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="m in members"
                                    :key="m.player_id"
                                    class="border-t border-zinc-800"
                                >
                                    <td class="px-4 py-2 text-zinc-100">{{ m.user_name }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-amber-300">
                                        {{ m.role }}
                                    </td>
                                    <td class="px-4 py-2 font-mono text-zinc-400">
                                        A{{ m.akzar_cash.toFixed(2) }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <template v-if="is_leader && m.player_id !== player_id">
                                            <button
                                                type="button"
                                                @click="promote(m.player_id, m.role === 'officer' ? 'member' : 'officer')"
                                                class="mr-2 text-xs text-amber-400 hover:underline"
                                            >
                                                {{ m.role === 'officer' ? 'Demote' : 'Promote' }}
                                            </button>
                                            <button
                                                type="button"
                                                @click="kick(m.player_id)"
                                                class="text-xs text-rose-400 hover:underline"
                                            >
                                                Kick
                                            </button>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Journal tab -->
                <div
                    v-if="tab === 'journal'"
                    id="mdn-panel-journal"
                    role="tabpanel"
                    aria-labelledby="mdn-tab-journal"
                    class="space-y-4"
                >
                    <form
                        v-if="is_member"
                        @submit.prevent="addEntry"
                        class="rounded border border-zinc-800 bg-zinc-900/50 p-3"
                    >
                        <label for="journal-body" class="sr-only">New journal entry</label>
                        <textarea
                            id="journal-body"
                            v-model="entryForm.body"
                            rows="2"
                            placeholder="Share a tip or warning with your MDN…"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 p-2 text-sm text-zinc-100"
                        ></textarea>
                        <div class="mt-2 flex justify-end">
                            <button
                                type="submit"
                                :disabled="entryForm.processing || !entryForm.body.trim()"
                                class="rounded bg-amber-500 px-3 py-1 font-mono text-xs font-bold uppercase tracking-widest text-zinc-950 hover:bg-amber-400 disabled:opacity-50"
                            >
                                Post entry
                            </button>
                        </div>
                    </form>

                    <div
                        v-for="e in journal"
                        :key="e.id"
                        class="rounded border border-zinc-800 bg-zinc-900/30 p-3"
                    >
                        <p class="whitespace-pre-wrap text-sm text-zinc-200">{{ e.body }}</p>
                        <div class="mt-2 flex items-center gap-3 text-xs text-zinc-500">
                            <button
                                v-if="is_member"
                                type="button"
                                @click="vote(e.id, 'helpful')"
                                class="hover:text-emerald-400"
                            >
                                ▲ Helpful ({{ e.helpful_count }})
                            </button>
                            <button
                                v-if="is_member"
                                type="button"
                                @click="vote(e.id, 'unhelpful')"
                                class="hover:text-rose-400"
                            >
                                ▼ Unhelpful ({{ e.unhelpful_count }})
                            </button>
                            <span class="ml-auto">{{ e.created_at }}</span>
                        </div>
                    </div>
                    <div
                        v-if="journal.length === 0"
                        class="rounded border border-dashed border-zinc-800 p-6 text-center text-sm text-zinc-500"
                    >
                        No journal entries yet.
                    </div>
                </div>

                <!-- Alliances tab -->
                <div
                    v-if="tab === 'alliances'"
                    id="mdn-panel-alliances"
                    role="tabpanel"
                    aria-labelledby="mdn-tab-alliances"
                    class="space-y-3"
                >
                    <button
                        v-if="is_leader"
                        type="button"
                        @click="declareAlliance"
                        class="rounded bg-amber-500 px-3 py-1 font-mono text-xs font-bold uppercase tracking-widest text-zinc-950 hover:bg-amber-400"
                    >
                        + Declare alliance
                    </button>

                    <div
                        v-for="a in alliances"
                        :key="a.id"
                        class="flex items-center justify-between gap-2 rounded border border-zinc-800 bg-zinc-900/30 p-3 text-sm"
                    >
                        <div v-if="a.other_mdn" class="min-w-0 break-words">
                            <span class="font-mono text-amber-300">[{{ a.other_mdn.tag }}]</span>
                            <span class="ml-2 text-zinc-200">{{ a.other_mdn.name }}</span>
                        </div>
                        <div v-else class="italic text-zinc-500">[Disbanded MDN]</div>
                        <button
                            v-if="is_leader"
                            type="button"
                            @click="revokeAlliance(a.id)"
                            class="text-xs text-rose-400 hover:underline"
                        >
                            Revoke
                        </button>
                    </div>
                    <div
                        v-if="alliances.length === 0"
                        class="rounded border border-dashed border-zinc-800 p-6 text-center text-sm text-zinc-500"
                    >
                        No declared alliances. Alliances are cosmetic — allied MDNs can still raid each other.
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
