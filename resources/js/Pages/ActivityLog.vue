<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

interface Entry {
    id: number;
    type: string;
    title: string;
    body: Record<string, unknown> | null;
    read_at: string | null;
    created_at: string;
}

interface Paginator {
    data: Entry[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

defineProps<{
    entries: Paginator;
    unread_count: number;
}>();

function markAllRead() {
    router.post(route('activity.read_all'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Activity Log — Clash Wars" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                    Activity Log
                </h2>
                <button
                    v-if="unread_count > 0"
                    type="button"
                    class="rounded border border-zinc-700 bg-zinc-900 px-3 py-1 font-mono text-xs uppercase tracking-widest text-zinc-300 hover:border-amber-400 hover:text-amber-400 transition"
                    @click="markAllRead"
                >
                    Mark all read ({{ unread_count }})
                </button>
            </div>
        </template>

        <div class="py-4 sm:py-8">
            <div class="mx-auto max-w-3xl px-3 sm:px-6 lg:px-8 space-y-3 font-mono">
                <div
                    v-if="entries.data.length === 0"
                    class="rounded border border-zinc-800 bg-zinc-900/60 p-6 text-center text-sm text-zinc-500"
                >
                    Nothing on record. The dust hasn't caught you yet.
                </div>

                <div
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="rounded border bg-zinc-900/60 p-3 sm:p-4"
                    :class="
                        entry.read_at
                            ? 'border-zinc-800 text-zinc-400'
                            : 'border-amber-700/40 text-zinc-100'
                    "
                >
                    <div class="flex items-start justify-between gap-2 sm:gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="text-[10px] uppercase tracking-widest text-zinc-500 mb-1 break-words">
                                {{ entry.type }} · {{ entry.created_at }}
                            </div>
                            <div class="text-sm font-bold leading-tight break-words">
                                {{ entry.title }}
                            </div>
                            <div
                                v-if="entry.body && Object.keys(entry.body).length > 0"
                                class="mt-2 text-[11px] text-zinc-500 space-y-0.5"
                            >
                                <div v-if="entry.body.outcome" class="flex gap-1">
                                    <span class="text-zinc-600">outcome:</span>
                                    <span :class="entry.body.outcome === 'success' ? 'text-rose-400' : 'text-emerald-400'">
                                        {{ entry.body.outcome === 'success' ? 'breached' : 'repelled' }}
                                    </span>
                                </div>
                                <div v-if="Number(entry.body.cash_stolen) > 0" class="flex gap-1">
                                    <span class="text-zinc-600">stolen:</span>
                                    <span class="text-rose-400">A{{ Number(entry.body.cash_stolen).toFixed(2) }}</span>
                                </div>
                                <div v-if="entry.body.tiles_added" class="flex gap-1">
                                    <span class="text-zinc-600">new tiles:</span>
                                    <span class="text-amber-400">{{ entry.body.tiles_added }}</span>
                                </div>
                                <div v-if="entry.body.spy_succeeded !== undefined" class="flex gap-1">
                                    <span class="text-zinc-600">intel compromised:</span>
                                    <span :class="entry.body.spy_succeeded ? 'text-rose-400' : 'text-emerald-400'">
                                        {{ entry.body.spy_succeeded ? 'yes' : 'no' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <span
                            v-if="!entry.read_at"
                            class="shrink-0 rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-zinc-950"
                        >
                            New
                        </span>
                    </div>
                </div>

                <div
                    class="flex items-center justify-between text-xs text-zinc-600 pt-2"
                >
                    <Link
                        v-if="entries.prev_page_url"
                        :href="entries.prev_page_url"
                        class="hover:text-amber-400"
                    >
                        ← prev
                    </Link>
                    <span>page {{ entries.current_page }} / {{ entries.last_page }}</span>
                    <Link
                        v-if="entries.next_page_url"
                        :href="entries.next_page_url"
                        class="hover:text-amber-400"
                    >
                        next →
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
