<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface Density {
    oil_fields_per_tile: number;
    posts_per_tile: number;
    landmarks_per_tile: number;
}

interface Growth {
    trigger_players_per_tile: number;
    expansion_ring_width: number;
}

interface Abandonment {
    days_inactive: number;
    ruin_loot_min: number;
    ruin_loot_max: number;
}

interface WorldInfo {
    initial_radius: number;
    density: Density;
    growth: Growth;
    abandonment: Abandonment;
}

defineProps<{
    world: WorldInfo;
}>();
</script>

<template>
    <Head title="World info (debug)" />

    <div class="min-h-screen bg-zinc-900 text-zinc-100 p-8 font-mono text-sm">
        <h1 class="text-xl font-bold mb-4 text-amber-400">Akzar — configured world shape</h1>
        <p class="text-zinc-400 mb-6">
            Placeholder debug view. Confirms the dual-layer controller pattern
            works: same <code>WorldService</code> backs both
            <code>/world</code> (Inertia) and <code>/api/v1/world/info</code> (JSON).
            Phase 1 replaces this with the real map view.
        </p>

        <pre class="bg-zinc-950 border border-zinc-800 rounded p-4 overflow-auto">{{ JSON.stringify(world, null, 2) }}</pre>
    </div>
</template>
