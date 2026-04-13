<script setup lang="ts">
import Toast from '@/Components/Toast.vue';
import { useNotifications } from '@/Composables/useNotifications';

const { toasts, dismiss } = useNotifications();
</script>

<template>
    <div
        class="pointer-events-none fixed left-2 right-2 top-2 sm:left-auto sm:right-4 sm:top-4 z-[70] flex flex-col gap-2 items-stretch sm:items-end"
        aria-live="polite"
        aria-atomic="false"
    >
        <Toast
            v-for="toast in toasts"
            :key="toast.id"
            :toast="toast"
            @dismiss="dismiss"
        />
    </div>
</template>
