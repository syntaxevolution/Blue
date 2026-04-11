<script setup lang="ts">
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    costBarrels: number;
    ownsTeleporter: boolean;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const x = ref<string>('');
const y = ref<string>('');
const checking = ref(false);
const exists = ref<boolean | null>(null);
const error = ref<string | null>(null);

async function validate() {
    error.value = null;
    exists.value = null;
    if (x.value === '' || y.value === '') {
        error.value = 'Enter both coordinates.';
        return;
    }
    checking.value = true;
    try {
        const res = await axios.get(route('map.tile_exists'), {
            params: { x: Number(x.value), y: Number(y.value) },
        });
        exists.value = Boolean(res.data?.exists);
    } catch (e) {
        error.value = 'Could not validate destination.';
    } finally {
        checking.value = false;
    }
}

function go() {
    if (exists.value !== true) return;
    router.post(
        route('map.teleport'),
        { x: Number(x.value), y: Number(y.value) },
        {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => emit('close'),
        },
    );
}
</script>

<template>
    <div class="fixed inset-0 z-30 flex items-center justify-center bg-zinc-950/80 backdrop-blur">
        <div class="w-full max-w-md rounded-lg border border-violet-600/50 bg-zinc-900 p-6 shadow-2xl">
            <div class="flex items-start justify-between mb-2">
                <h2 class="font-mono text-xl font-bold uppercase tracking-widest text-violet-300">
                    Teleporter
                </h2>
                <button type="button" class="text-zinc-400 hover:text-zinc-100" @click="emit('close')">×</button>
            </div>

            <p v-if="!ownsTeleporter" class="text-rose-300 text-sm font-mono">
                You do not own a Teleporter. Visit a General Store to purchase one.
            </p>

            <template v-else>
                <p class="text-zinc-400 text-sm mb-4 leading-relaxed">
                    Enter target coordinates. Each jump costs
                    <span class="text-amber-400 font-bold">{{ costBarrels }} barrels</span>.
                    Invalid destinations do not charge.
                </p>

                <div class="grid grid-cols-2 gap-3 mb-3">
                    <input
                        v-model="x"
                        type="number"
                        placeholder="X"
                        class="rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-zinc-100 focus:border-violet-400 focus:outline-none"
                    />
                    <input
                        v-model="y"
                        type="number"
                        placeholder="Y"
                        class="rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-zinc-100 focus:border-violet-400 focus:outline-none"
                    />
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="flex-1 rounded-md border border-zinc-700 bg-zinc-900 px-4 py-2 font-mono text-sm font-bold uppercase tracking-wider text-zinc-300 hover:border-amber-400 hover:text-amber-400 transition"
                        :disabled="checking"
                        @click="validate"
                    >
                        Validate
                    </button>
                    <button
                        type="button"
                        class="flex-1 rounded-md bg-violet-600 px-4 py-2 font-mono text-sm font-bold uppercase tracking-wider text-zinc-50 hover:bg-violet-500 transition disabled:opacity-30"
                        :disabled="exists !== true"
                        @click="go"
                    >
                        Teleport ({{ costBarrels }}⛽)
                    </button>
                </div>

                <div v-if="exists === false" class="mt-3 text-rose-400 text-xs font-mono">
                    Destination does not exist. No charge applied.
                </div>
                <div v-if="exists === true" class="mt-3 text-emerald-400 text-xs font-mono">
                    Destination confirmed.
                </div>
                <div v-if="error" class="mt-3 text-rose-400 text-xs font-mono">
                    {{ error }}
                </div>
            </template>
        </div>
    </div>
</template>
