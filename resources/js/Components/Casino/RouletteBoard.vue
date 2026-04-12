<script setup lang="ts">
import { computed } from 'vue';

const emit = defineEmits<{
    bet: [betType: string, numbers: number[]];
}>();

const RED_NUMBERS = [1,3,5,7,9,12,14,16,18,19,21,23,25,27,30,32,34,36];

const rows = [
    [3,6,9,12,15,18,21,24,27,30,33,36],
    [2,5,8,11,14,17,20,23,26,29,32,35],
    [1,4,7,10,13,16,19,22,25,28,31,34],
];

function numberColor(n: number): string {
    if (n === 0) return 'green';
    return RED_NUMBERS.includes(n) ? 'red' : 'black';
}

function colorClass(n: number): string {
    const c = numberColor(n);
    if (c === 'red') return 'bg-red-700 hover:bg-red-600';
    if (c === 'black') return 'bg-zinc-800 hover:bg-zinc-700';
    return 'bg-green-700 hover:bg-green-600';
}

function placeStraight(n: number) {
    emit('bet', 'straight', [n]);
}
</script>

<template>
    <div class="overflow-x-auto">
        <div class="inline-block min-w-[480px]">
            <!-- Zero -->
            <div class="mb-1 flex">
                <button
                    class="h-16 w-12 rounded bg-green-700 text-sm font-bold text-white transition-colors hover:bg-green-600"
                    @click="placeStraight(0)"
                >
                    0
                </button>
            </div>

            <!-- Number grid -->
            <div class="space-y-0.5">
                <div v-for="(row, ri) in rows" :key="ri" class="flex gap-0.5">
                    <button
                        v-for="n in row"
                        :key="n"
                        class="h-10 w-10 rounded text-xs font-semibold text-white transition-colors"
                        :class="colorClass(n)"
                        @click="placeStraight(n)"
                    >
                        {{ n }}
                    </button>
                </div>
            </div>

            <!-- Outside bets -->
            <div class="mt-2 flex flex-wrap gap-1">
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'red', [])"
                >Red</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'black', [])"
                >Black</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'odd', [])"
                >Odd</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'even', [])"
                >Even</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'low', [])"
                >1-18</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'high', [])"
                >19-36</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'dozen_1', [])"
                >1st 12</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'dozen_2', [])"
                >2nd 12</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'dozen_3', [])"
                >3rd 12</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'column_1', [])"
                >Col 1</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'column_2', [])"
                >Col 2</button>
                <button
                    class="rounded border border-zinc-600 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 transition-colors hover:border-zinc-500"
                    @click="emit('bet', 'column_3', [])"
                >Col 3</button>
            </div>
        </div>
    </div>
</template>
