<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    open: boolean;
    userName: string | null;
    userEmail: string | null;
    unreadHostility: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

function close() {
    emit('close');
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') close();
}

watch(
    () => props.open,
    (isOpen) => {
        if (typeof document === 'undefined') return;
        if (isOpen) {
            document.addEventListener('keydown', onKey);
            document.body.style.overflow = 'hidden';
        } else {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        }
    },
);

onBeforeUnmount(() => {
    if (typeof document !== 'undefined') {
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 bg-zinc-950/70 backdrop-blur-sm sm:hidden"
                @click.self="close"
                aria-hidden="true"
            ></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-200 ease-out"
            enter-from-class="translate-y-full"
            enter-to-class="translate-y-0"
            leave-active-class="transition-transform duration-150 ease-in"
            leave-from-class="translate-y-0"
            leave-to-class="translate-y-full"
        >
            <div
                v-if="open"
                role="dialog"
                aria-modal="true"
                aria-label="More navigation"
                class="fixed inset-x-0 bottom-0 z-50 max-h-[85vh] overflow-y-auto rounded-t-2xl border-t border-zinc-800 bg-zinc-950 shadow-2xl safe-bottom sm:hidden"
            >
                <div class="mx-auto max-w-xl px-4 pb-2 pt-3">
                    <div
                        class="mx-auto mb-3 h-1 w-12 rounded-full bg-zinc-700"
                        aria-hidden="true"
                    ></div>

                    <div class="mb-3 border-b border-zinc-800 pb-3">
                        <div class="text-base font-bold text-zinc-100">
                            {{ userName ?? 'Player' }}
                        </div>
                        <div
                            v-if="userEmail"
                            class="text-xs text-zinc-500"
                        >
                            {{ userEmail }}
                        </div>
                    </div>

                    <ul class="space-y-1">
                        <li>
                            <Link
                                :href="route('dashboard')"
                                class="tap-target flex w-full items-center justify-between rounded-md px-3 py-3 text-sm font-semibold text-zinc-200 active:bg-zinc-800"
                                @click="close"
                            >
                                <span>Dashboard</span>
                                <svg
                                    class="h-4 w-4 text-zinc-500"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path d="m9 18 6-6-6-6" />
                                </svg>
                            </Link>
                        </li>
                        <li>
                            <Link
                                :href="route('attack_log.show')"
                                class="tap-target flex w-full items-center justify-between rounded-md px-3 py-3 text-sm font-semibold text-zinc-200 active:bg-zinc-800"
                                @click="close"
                            >
                                <span class="inline-flex items-center gap-2">
                                    Hostility Log
                                    <span
                                        v-if="unreadHostility > 0"
                                        class="rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold text-zinc-950"
                                    >
                                        {{ unreadHostility > 99 ? '99+' : unreadHostility }}
                                    </span>
                                </span>
                                <svg
                                    class="h-4 w-4 text-zinc-500"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path d="m9 18 6-6-6-6" />
                                </svg>
                            </Link>
                        </li>
                        <li>
                            <Link
                                :href="route('profile.edit')"
                                class="tap-target flex w-full items-center justify-between rounded-md px-3 py-3 text-sm font-semibold text-zinc-200 active:bg-zinc-800"
                                @click="close"
                            >
                                <span>Profile</span>
                                <svg
                                    class="h-4 w-4 text-zinc-500"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path d="m9 18 6-6-6-6" />
                                </svg>
                            </Link>
                        </li>
                        <li>
                            <Link
                                :href="route('logout')"
                                method="post"
                                as="button"
                                class="tap-target flex w-full items-center justify-between rounded-md px-3 py-3 text-left text-sm font-semibold text-rose-300 active:bg-zinc-800"
                                @click="close"
                            >
                                <span>Log Out</span>
                                <svg
                                    class="h-4 w-4 text-zinc-500"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                    <path d="m16 17 5-5-5-5M21 12H9" />
                                </svg>
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
