<script setup lang="ts">
import type { ToastPayload } from '@/Composables/useNotifications';

defineProps<{ toast: ToastPayload }>();
defineEmits<{ (e: 'dismiss', id: string): void }>();

function accent(type: string): string {
    if (type.startsWith('attack')) return 'border-rose-600 bg-rose-950/80 text-rose-100';
    if (type.startsWith('spy')) return 'border-violet-600 bg-violet-950/80 text-violet-100';
    if (type.startsWith('raid')) return 'border-amber-600 bg-amber-950/80 text-amber-100';
    return 'border-zinc-700 bg-zinc-900/90 text-zinc-100';
}
</script>

<template>
    <div
        class="pointer-events-auto w-80 rounded-lg border-2 p-4 shadow-xl font-mono text-sm"
        :class="accent(toast.type)"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="flex-1">
                <div class="font-bold uppercase tracking-wider text-xs mb-1 opacity-80">
                    {{ toast.type.replace('.', ' · ') }}
                </div>
                <div class="text-base font-semibold leading-tight">
                    {{ toast.title }}
                </div>
            </div>
            <button
                type="button"
                class="text-zinc-400 hover:text-zinc-100"
                @click="$emit('dismiss', toast.id)"
                aria-label="Dismiss"
            >
                ×
            </button>
        </div>
    </div>
</template>
