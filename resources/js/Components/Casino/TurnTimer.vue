<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps<{
    seconds: number;
    active: boolean;
}>();

const remaining = ref(props.seconds);
let interval: ReturnType<typeof setInterval> | null = null;

watch(() => props.active, (active) => {
    if (interval) { clearInterval(interval); interval = null; }
    if (active) {
        remaining.value = props.seconds;
        interval = setInterval(() => {
            remaining.value = Math.max(0, remaining.value - 1);
            if (remaining.value <= 0 && interval) {
                clearInterval(interval);
                interval = null;
            }
        }, 1000);
    }
}, { immediate: true });

watch(() => props.seconds, (s) => { remaining.value = s; });

onBeforeUnmount(() => { if (interval) clearInterval(interval); });
</script>

<template>
    <div v-if="active" class="flex items-center gap-1.5">
        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-zinc-700">
            <div
                class="h-full rounded-full transition-all duration-1000"
                :class="remaining <= 5 ? 'bg-red-500' : remaining <= 10 ? 'bg-amber-500' : 'bg-green-500'"
                :style="{ width: `${(remaining / seconds) * 100}%` }"
            ></div>
        </div>
        <span class="text-xs font-mono" :class="remaining <= 5 ? 'text-red-400' : 'text-zinc-400'">
            {{ remaining }}s
        </span>
    </div>
</template>
