<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
}>();

const features = [
    {
        title: 'Fog of war',
        body: 'Akzar only opens as you walk it. No minimap. Your journal is the only thing that remembers where the fields were.',
        accent: 'text-amber-400',
    },
    {
        title: 'Drill for oil',
        body: 'Every field is a 5×5 grid of possibility. Dry points waste a move. Gushers change the week. Better drills narrow the bad.',
        accent: 'text-emerald-400',
    },
    {
        title: 'Raid rivals',
        body: 'Spy first, strike hard, take up to 20%. No one-shot wipes. No flat-wealth RNG. Spread beats spike.',
        accent: 'text-rose-400',
    },
    {
        title: 'Form MDNs',
        body: 'Bring fifty friends. Hard-blocked from hitting each other. Ratings-sorted shared journal. Coordinated contracts.',
        accent: 'text-violet-400',
    },
];
</script>

<template>
    <Head title="Clash Wars — The dust never settles" />

    <div
        class="min-h-screen bg-zinc-950 text-zinc-100 bg-[radial-gradient(ellipse_at_top,rgba(251,191,36,0.10),transparent_60%)]"
    >
        <!-- Top bar -->
        <header class="mx-auto max-w-6xl px-6 py-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <ApplicationLogo class="h-10 w-10 text-amber-400" />
                <span class="font-mono text-xl font-black uppercase tracking-widest text-amber-400">
                    Clash Wars
                </span>
            </div>

            <nav v-if="canLogin" class="flex items-center gap-2">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="rounded-md border border-zinc-800 px-4 py-2 text-sm font-mono uppercase tracking-wider text-zinc-300 hover:border-amber-400 hover:text-amber-400 transition"
                >
                    Enter Akzar
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded-md px-4 py-2 text-sm font-mono uppercase tracking-wider text-zinc-300 hover:text-amber-400 transition"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="rounded-md bg-amber-500 px-4 py-2 text-sm font-mono font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition shadow-lg shadow-amber-900/30"
                    >
                        Claim a tile
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero -->
        <section class="mx-auto max-w-6xl px-6 pt-16 pb-24">
            <div class="max-w-3xl">
                <div class="mb-4 inline-block rounded border border-amber-700/40 bg-amber-950/40 px-3 py-1 font-mono text-xs uppercase tracking-widest text-amber-400">
                    A frontier rebuild of the 2001 classic
                </div>
                <h1 class="font-mono text-5xl md:text-7xl font-black uppercase leading-none tracking-tight text-zinc-50">
                    Drill it.<br />
                    Raid it.<br />
                    <span class="text-amber-400">Claim it.</span>
                </h1>
                <p class="mt-8 text-lg md:text-xl text-zinc-400 leading-relaxed max-w-2xl">
                    Akzar is a dust-choked oil world, abandoned by its colonial charter
                    and run by squatters, drillers, and raiders. Start with five Akzar
                    cash and a toy drill. Every move matters. No pay-to-win.
                    No cash-out. Just the grid, the fog, and whoever's got the bigger base.
                </p>

                <div v-if="canLogin && !$page.props.auth.user" class="mt-10 flex flex-wrap gap-3">
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="inline-flex items-center rounded-md bg-amber-500 px-6 py-3 font-mono text-base font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition shadow-xl shadow-amber-900/30"
                    >
                        Land on Akzar →
                    </Link>
                    <Link
                        :href="route('login')"
                        class="inline-flex items-center rounded-md border border-zinc-700 px-6 py-3 font-mono text-base uppercase tracking-wider text-zinc-300 hover:border-amber-400 hover:text-amber-400 transition"
                    >
                        Log in
                    </Link>
                </div>

                <div v-else-if="$page.props.auth.user" class="mt-10">
                    <Link
                        :href="route('map.show')"
                        class="inline-flex items-center rounded-md bg-amber-500 px-6 py-3 font-mono text-base font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition shadow-xl shadow-amber-900/30"
                    >
                        Enter the map →
                    </Link>
                </div>
            </div>
        </section>

        <!-- Feature grid -->
        <section class="border-t border-zinc-900 bg-zinc-950/40">
            <div class="mx-auto max-w-6xl px-6 py-20">
                <div class="mb-12 max-w-2xl">
                    <div class="font-mono text-xs uppercase tracking-widest text-zinc-500 mb-3">
                        What you came for
                    </div>
                    <h2 class="font-mono text-3xl md:text-4xl font-black uppercase text-zinc-100">
                        A turn-limited strategy game that respects your time
                    </h2>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="rounded-lg border border-zinc-800 bg-zinc-900/60 p-6 hover:border-zinc-700 transition"
                    >
                        <h3
                            class="font-mono text-xl font-bold uppercase tracking-wider mb-3"
                            :class="feature.accent"
                        >
                            {{ feature.title }}
                        </h3>
                        <p class="text-zinc-400 leading-relaxed">
                            {{ feature.body }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Design pillars -->
        <section class="border-t border-zinc-900">
            <div class="mx-auto max-w-6xl px-6 py-20">
                <div class="grid gap-8 md:grid-cols-3 text-sm">
                    <div>
                        <div class="font-mono text-xs uppercase tracking-widest text-amber-400 mb-2">
                            No pay-to-win
                        </div>
                        <p class="text-zinc-400">
                            No premium currency. No energy refills for cash. No shortcuts.
                            Virtual currency only — exactly how the original should have been.
                        </p>
                    </div>
                    <div>
                        <div class="font-mono text-xs uppercase tracking-widest text-amber-400 mb-2">
                            Deterministic combat
                        </div>
                        <p class="text-zinc-400">
                            A tight ±10–15% RNG band, a 20% loot ceiling, and a soft stat
                            plateau at 15. Spread beats spike. Planning beats luck.
                        </p>
                    </div>
                    <div>
                        <div class="font-mono text-xs uppercase tracking-widest text-amber-400 mb-2">
                            Live-tunable
                        </div>
                        <p class="text-zinc-400">
                            Every balance value lives in config, not code. The economy
                            adapts without a deploy. Every roll is seeded and auditable.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-zinc-900">
            <div class="mx-auto max-w-6xl px-6 py-10 flex flex-col md:flex-row items-center justify-between gap-4 text-xs font-mono uppercase tracking-widest text-zinc-600">
                <div>Clash Wars — a remake, with the RNG trap fixed.</div>
                <div>Persistent world · Single shared grid · ~100 player alpha</div>
            </div>
        </footer>
    </div>
</template>
