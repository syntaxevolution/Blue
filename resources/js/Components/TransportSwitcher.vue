<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface TransportConfig {
    key: string;
    cost_barrels: number;
    spaces: number;
    fuel: number;
    flags: string[];
}

const props = defineProps<{
    active: string;
    owned: string[];
    catalog: Record<string, TransportConfig>;
}>();

const hasNonDefault = computed(() => props.owned.some((k) => k !== 'walking'));

function switchTo(key: string) {
    if (!props.owned.includes(key)) return;
    router.post(route('map.transport'), { transport: key }, { preserveScroll: true, preserveState: false });
}

function label(key: string): string {
    return key
        .split('_')
        .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
        .join(' ');
}
</script>

<template>
    <div class="flex items-center gap-2 text-xs font-mono">
        <span class="text-zinc-500 uppercase tracking-widest">Transport:</span>

        <div v-if="!hasNonDefault" class="flex items-center gap-1 text-zinc-600">
            <span>🔒</span>
            <span>Walking (buy a bicycle or better)</span>
        </div>

        <div v-else class="flex flex-wrap gap-1">
            <button
                v-for="key in owned"
                :key="key"
                type="button"
                class="rounded border px-2 py-1 transition"
                :class="
                    key === active
                        ? 'border-amber-400 bg-amber-500/20 text-amber-300'
                        : 'border-zinc-700 bg-zinc-900 text-zinc-400 hover:border-amber-400 hover:text-amber-300'
                "
                @click="switchTo(key)"
                :title="
                    catalog[key]
                        ? `${catalog[key].spaces} spaces/press, ${catalog[key].fuel} fuel`
                        : key
                "
            >
                {{ label(key) }}
                <span v-if="catalog[key] && key !== 'walking'" class="ml-1 text-[10px] text-zinc-500">
                    {{ catalog[key].spaces }}·{{ catalog[key].fuel }}⛽
                </span>
            </button>
        </div>
    </div>
</template>
