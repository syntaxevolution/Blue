<script setup lang="ts">
import Toast from '@/Components/Toast.vue';
import { useNotifications } from '@/Composables/useNotifications';

const { toasts, dismiss } = useNotifications();
</script>

<template>
    <div
        class="pointer-events-none fixed right-4 top-4 z-50 flex flex-col gap-2"
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
