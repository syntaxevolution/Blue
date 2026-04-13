<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    unreadActivity: number;
    unreadHostility: number;
}>();

const emit = defineEmits<{
    (e: 'open-more'): void;
}>();

interface TabDef {
    key: string;
    label: string;
    href: string;
    routeMatch: string;
    badge: 'activity' | null;
}

const tabs = computed<TabDef[]>(() => [
    {
        key: 'map',
        label: 'Map',
        href: route('map.show'),
        routeMatch: 'map.show',
        badge: null,
    },
    {
        key: 'atlas',
        label: 'Atlas',
        href: route('atlas.show'),
        routeMatch: 'atlas.show',
        badge: null,
    },
    {
        key: 'activity',
        label: 'Activity',
        href: route('activity.index'),
        routeMatch: 'activity.index',
        badge: 'activity',
    },
    {
        key: 'mdn',
        label: 'MDN',
        href: route('mdn.index'),
        routeMatch: 'mdn.*',
        badge: null,
    },
]);

function isActive(routeMatch: string): boolean {
    return Boolean(route().current(routeMatch));
}

function badgeCount(tab: TabDef): number {
    if (tab.badge === 'activity') return props.unreadActivity;
    return 0;
}

const moreHasBadge = computed(() => props.unreadHostility > 0);
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-800 bg-zinc-950/95 backdrop-blur safe-bottom-0 sm:hidden"
        aria-label="Primary mobile navigation"
    >
        <ul class="mx-auto flex max-w-xl items-stretch justify-around px-1 pt-1">
            <li v-for="tab in tabs" :key="tab.key" class="flex-1">
                <Link
                    :href="tab.href"
                    class="relative flex h-14 w-full flex-col items-center justify-center gap-0.5 rounded-md text-[11px] font-semibold uppercase tracking-wider transition-colors"
                    :class="
                        isActive(tab.routeMatch)
                            ? 'text-amber-400'
                            : 'text-zinc-400 active:text-amber-300'
                    "
                >
                    <!-- Icon -->
                    <svg
                        v-if="tab.key === 'map'"
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M9 4.5 3 7v13l6-2.5 6 2.5 6-2.5V4.5L15 7z" />
                        <path d="M9 4.5v13M15 7v13" />
                    </svg>
                    <svg
                        v-else-if="tab.key === 'atlas'"
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <rect x="3" y="3" width="18" height="18" rx="2" />
                        <path d="M3 9h18M3 15h18M9 3v18M15 3v18" />
                    </svg>
                    <svg
                        v-else-if="tab.key === 'activity'"
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                        <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                    </svg>
                    <svg
                        v-else-if="tab.key === 'mdn'"
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2" />
                        <circle cx="10" cy="7" r="4" />
                        <path d="M21 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M17 3.13a4 4 0 0 1 0 7.75" />
                    </svg>

                    <span>{{ tab.label }}</span>

                    <span
                        v-if="badgeCount(tab) > 0"
                        class="absolute right-1 top-1 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-amber-500 px-1 py-0.5 text-[10px] font-bold text-zinc-950"
                    >
                        {{ badgeCount(tab) > 99 ? '99+' : badgeCount(tab) }}
                    </span>
                </Link>
            </li>

            <!-- More -->
            <li class="flex-1">
                <button
                    type="button"
                    @click="emit('open-more')"
                    class="relative flex h-14 w-full flex-col items-center justify-center gap-0.5 rounded-md text-[11px] font-semibold uppercase tracking-wider text-zinc-400 transition-colors active:text-amber-300"
                >
                    <svg
                        class="h-5 w-5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <circle cx="5" cy="12" r="1.5" />
                        <circle cx="12" cy="12" r="1.5" />
                        <circle cx="19" cy="12" r="1.5" />
                    </svg>
                    <span>More</span>

                    <span
                        v-if="moreHasBadge"
                        class="absolute right-1 top-1 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1 py-0.5 text-[10px] font-bold text-zinc-950"
                    >
                        {{ props.unreadHostility > 99 ? '99+' : props.unreadHostility }}
                    </span>
                </button>
            </li>
        </ul>
    </nav>
</template>
