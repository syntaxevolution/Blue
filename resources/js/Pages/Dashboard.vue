<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    startingCash: string;
    dailyRegen: number;
    bankCap: number;
    immunityHours: number;
}>();
</script>

<template>
    <Head title="Dashboard — Clash Wars" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="rounded-lg border border-zinc-800 bg-zinc-900 p-8">
                    <div class="font-mono text-xs uppercase tracking-widest text-zinc-500 mb-2">
                        Welcome to Akzar
                    </div>
                    <h3 class="font-mono text-3xl font-black uppercase text-zinc-100 mb-4">
                        The dust never settles.
                    </h3>
                    <p class="text-zinc-400 leading-relaxed mb-6 max-w-2xl">
                        You've landed on a world of cracked earth, rusted rigs, and cash
                        stashed behind reinforced doors. Your base is already claimed.
                        The oil fields are waiting. Head to the map to start drilling,
                        scouting, and picking fights.
                    </p>
                    <Link
                        :href="route('map.show')"
                        class="inline-flex items-center rounded-md bg-amber-500 px-6 py-3 font-mono text-base font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition shadow-xl shadow-amber-900/30"
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
