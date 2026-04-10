<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center bg-zinc-950 px-4 py-10 bg-[radial-gradient(ellipse_at_top,rgba(251,191,36,0.08),transparent_60%)]"
    >
        <Link href="/" class="flex flex-col items-center gap-3">
            <ApplicationLogo class="h-20 w-20 text-amber-400" />
            <div class="flex flex-col items-center">
                <span
                    class="font-mono text-2xl font-black uppercase tracking-[0.2em] text-amber-400"
                >
                    Cash Clash
                </span>
                <span class="mt-1 text-xs uppercase tracking-widest text-zinc-500">
                    The dust never settles
                </span>
            </div>
        </Link>

        <div
            class="mt-8 w-full max-w-md overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900 px-6 py-8 shadow-2xl shadow-black/50"
        >
            <slot />
        </div>
    </div>
</template>
