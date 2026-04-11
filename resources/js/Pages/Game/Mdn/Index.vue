<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface MdnRow {
    id: number;
    name: string;
    tag: string;
    member_count: number;
    motto: string | null;
    leader_player_id: number;
}

const props = defineProps<{
    mdns: MdnRow[];
    own_mdn: MdnRow | null;
    player_id: number;
    max_members: number;
    creation_cost: number;
}>();

const page = usePage();
const flash = computed(() => page.props.flash as { status?: string } | undefined);
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const search = ref('');

const filtered = computed<MdnRow[]>(() => {
    const q = search.value.trim().toLowerCase();
    if (q === '') return props.mdns;
    return props.mdns.filter(
        (m) => m.name.toLowerCase().includes(q) || m.tag.toLowerCase().includes(q),
    );
});

function join(mdnId: number) {
    router.post(route('mdn.join', mdnId));
}
</script>

<template>
    <Head title="MDNs — Mutual Defense Networks" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-black uppercase tracking-widest text-amber-400">
                Mutual Defense Networks
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
                    v-if="errors.mdn"
                    class="rounded border border-rose-700 bg-rose-900/40 px-4 py-2 text-sm text-rose-200"
                >
                    {{ errors.mdn }}
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Search MDN by name or tag…"
                        aria-label="Search MDNs by name or tag"
                        class="w-full sm:flex-1 rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100 placeholder-zinc-500 focus:border-amber-500 focus:outline-none"
                    />
                    <Link
                        v-if="!own_mdn"
                        :href="route('mdn.create')"
                        class="rounded bg-amber-500 px-4 py-2 font-mono text-sm font-bold uppercase tracking-widest text-zinc-950 hover:bg-amber-400 text-center whitespace-nowrap"
                    >
                        + Create MDN (A{{ creation_cost.toFixed(2) }})
                    </Link>
                </div>

                <div
                    v-if="own_mdn"
                    class="rounded border border-amber-700 bg-zinc-900/60 p-4"
                >
                    <div class="text-xs uppercase tracking-widest text-amber-400">
                        Your MDN
                    </div>
                    <div class="mt-1 flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0 break-words">
                            <span class="font-mono text-amber-300">[{{ own_mdn.tag }}]</span>
                            <span class="ml-2 font-bold text-zinc-100">{{ own_mdn.name }}</span>
                            <span class="ml-2 text-xs text-zinc-500">
                                {{ own_mdn.member_count }}/{{ max_members }} members
                            </span>
                        </div>
                        <Link
                            :href="route('mdn.show', own_mdn.id)"
                            class="text-sm text-amber-400 hover:underline whitespace-nowrap"
                        >
                            View →
                        </Link>
                    </div>
                </div>

                <div class="overflow-x-auto rounded border border-zinc-800">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-zinc-900 text-xs uppercase tracking-widest text-zinc-400">
                            <tr>
                                <th class="px-4 py-2">Tag</th>
                                <th class="px-4 py-2">Name</th>
                                <th class="px-4 py-2">Members</th>
                                <th class="px-4 py-2">Motto</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="m in filtered"
                                :key="m.id"
                                class="border-t border-zinc-800 hover:bg-zinc-900/50"
                            >
                                <td class="px-4 py-3 font-mono text-amber-300">
                                    [{{ m.tag }}]
                                </td>
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('mdn.show', m.id)"
                                        class="font-semibold text-zinc-100 hover:text-amber-400"
                                    >
                                        {{ m.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3 text-zinc-400">
                                    {{ m.member_count }} / {{ max_members }}
                                </td>
                                <td class="px-4 py-3 text-xs text-zinc-500">
                                    {{ m.motto ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="!own_mdn && m.member_count < max_members"
                                        type="button"
                                        @click="join(m.id)"
                                        class="rounded bg-zinc-800 px-3 py-1 text-xs font-mono uppercase tracking-widest text-amber-400 hover:bg-amber-500 hover:text-zinc-950"
                                    >
                                        Join
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="filtered.length === 0">
                                <td
                                    colspan="5"
                                    class="px-4 py-8 text-center text-sm text-zinc-500"
                                >
                                    No MDNs match your search.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
