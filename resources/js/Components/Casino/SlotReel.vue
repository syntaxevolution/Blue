<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';

const props = defineProps<{
    symbol: string | null;
    spinning: boolean;
    delay: number;
}>();

const displaySymbol = ref(props.symbol ?? '?');
const isAnimating = ref(false);

const symbolDisplayMap: Record<string, string> = {
    cherry: '🍒',
    bar: 'BAR',
    double_bar: '2x',
    triple_bar: '3x',
    seven: '7',
    diamond: '💎',
    akzar: '🛢️',
    blank: '—',
};

const dummySymbols = ['🍒', 'BAR', '7', '💎', '2x', '3x', '🛢️', '—'];

let animInterval: ReturnType<typeof setInterval> | null = null;

function display(sym: string): string {
    return symbolDisplayMap[sym] ?? sym;
}

watch(() => props.spinning, (spinning) => {
    if (spinning) {
        isAnimating.value = true;
        let tick = 0;
        animInterval = setInterval(() => {
            displaySymbol.value = dummySymbols[tick % dummySymbols.length];
            tick++;
        }, 80);
    } else {
        setTimeout(() => {
            if (animInterval) {
                clearInterval(animInterval);
                animInterval = null;
            }
            displaySymbol.value = props.symbol ? display(props.symbol) : '?';
            isAnimating.value = false;
        }, props.delay);
    }
});

watch(() => props.symbol, (sym) => {
    if (!props.spinning && sym) {
        displaySymbol.value = display(sym);
    }
});

onMounted(() => {
    if (props.symbol) {
        displaySymbol.value = display(props.symbol);
    }
});
</script>

<template>
    <div
        class="flex h-20 w-16 items-center justify-center rounded-lg border-2 text-2xl font-bold transition-all sm:h-24 sm:w-20 sm:text-3xl"
        :class="isAnimating
            ? 'border-amber-500/50 bg-zinc-700/80 text-zinc-300'
            : 'border-zinc-600 bg-zinc-800 text-zinc-100'"
    >
        <span :class="{ 'animate-pulse': isAnimating }">
            {{ displaySymbol }}
        </span>
    </div>
</template>
