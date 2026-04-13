<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, computed, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import BrokenItemModal from '@/Components/BrokenItemModal.vue';
import ClaimUsernameModal from '@/Components/ClaimUsernameModal.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import MobileMoreDrawer from '@/Components/MobileMoreDrawer.vue';
import MobileTabBar from '@/Components/MobileTabBar.vue';
import NavLink from '@/Components/NavLink.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import ToolboxDock from '@/Components/Toolbox/ToolboxDock.vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    subscribeToUserNotifications,
    badgeDeltas,
    resetActivityBadgeDelta,
    resetHostilityBadgeDelta,
} from '@/Composables/useNotifications';

const page = usePage();
const showingMoreDrawer = ref(false);

const authUser = computed(() => page.props.auth?.user ?? null);
const requiresClaim = computed(() => Boolean(page.props.auth?.requires_username_claim));
const brokenItemKey = computed<string | null>(
    () => (page.props.auth?.broken_item_key as string | null) ?? null,
);

interface BrokenItemPayload {
    key: string;
    name: string;
    repair_cost_barrels: number;
    player_barrels: number;
}

const brokenItem = computed<BrokenItemPayload | null>(
    () => (page.props.auth?.broken_item as BrokenItemPayload | null) ?? null,
);

// Server-authoritative unread counts, refreshed on every Inertia
// visit via HandleInertiaRequests::share(). The displayed value adds
// an in-memory delta that broadcast events bump in real time; when
// the server count changes (i.e. after a navigation), the watcher
// zeros out the delta so we don't double-count.
const serverActivityCount = computed<number>(
    () => Number(page.props.auth?.unread_activity_count ?? 0),
);
const serverHostilityCount = computed<number>(
    () => Number(page.props.auth?.unread_hostility_count ?? 0),
);

watch(serverActivityCount, () => {
    resetActivityBadgeDelta();
});
watch(serverHostilityCount, () => {
    resetHostilityBadgeDelta();
});

const unreadCount = computed<number>(
    () => serverActivityCount.value + badgeDeltas.activity,
);
const unreadHostilityCount = computed<number>(
    () => serverHostilityCount.value + badgeDeltas.hostility,
);

let teardown: () => void = () => undefined;

onMounted(() => {
    const uid = authUser.value?.id ?? null;
    if (uid) {
        teardown = subscribeToUserNotifications(uid);
    }
});

onBeforeUnmount(() => {
    teardown();
});
</script>

<template>
    <div class="min-h-screen bg-zinc-950 text-zinc-100">
        <nav class="border-b border-zinc-800 bg-zinc-900/80 backdrop-blur">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 justify-between">
                    <div class="flex">
                        <!-- Logo + wordmark -->
                        <div class="flex shrink-0 items-center gap-2">
                            <Link :href="route('dashboard')" class="flex items-center gap-2">
                                <ApplicationLogo class="block h-9 w-9 text-amber-400" />
                                <span
                                    class="hidden font-mono text-lg font-black uppercase tracking-widest text-amber-400 sm:inline"
                                >
                                    Clash Wars
                                </span>
                            </Link>
                        </div>

                        <!-- Navigation Links -->
                        <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                            <NavLink
                                :href="route('dashboard')"
                                :active="route().current('dashboard')"
                            >
                                Dashboard
                            </NavLink>
                            <NavLink
                                :href="route('map.show')"
                                :active="route().current('map.show')"
                            >
                                Map
                            </NavLink>
                            <NavLink
                                :href="route('atlas.show')"
                                :active="route().current('atlas.show')"
                            >
                                Atlas
                            </NavLink>
                            <NavLink
                                :href="route('attack_log.show')"
                                :active="route().current('attack_log.show')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    Hostility Log
                                    <span
                                        v-if="unreadHostilityCount > 0"
                                        class="rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-zinc-950"
                                    >
                                        {{ unreadHostilityCount }}
                                    </span>
                                </span>
                            </NavLink>
                            <NavLink
                                :href="route('mdn.index')"
                                :active="route().current('mdn.*')"
                            >
                                MDN
                            </NavLink>
                            <NavLink
                                :href="route('activity.index')"
                                :active="route().current('activity.index')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    Activity
                                    <span
                                        v-if="unreadCount > 0"
                                        class="rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-zinc-950"
                                    >
                                        {{ unreadCount }}
                                    </span>
                                </span>
                            </NavLink>
                        </div>
                    </div>

                    <div class="hidden sm:ms-6 sm:flex sm:items-center">
                        <!-- Settings Dropdown -->
                        <div class="relative ms-3">
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <span class="inline-flex rounded-md">
                                        <button
                                            type="button"
                                            class="inline-flex items-center rounded-md border border-transparent bg-transparent px-3 py-2 text-sm font-medium leading-4 text-zinc-300 transition duration-150 ease-in-out hover:text-amber-400 focus:outline-none"
                                        >
                                            {{ authUser?.name }}

                                            <svg
                                                class="-me-0.5 ms-2 h-4 w-4"
                                                xmlns="http://www.w3.org/2000/svg"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                            >
                                                <path
                                                    fill-rule="evenodd"
                                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                    clip-rule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </span>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">
                                        Profile
                                    </DropdownLink>
                                    <DropdownLink
                                        :href="route('logout')"
                                        method="post"
                                        as="button"
                                    >
                                        Log Out
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>

                </div>
            </div>
        </nav>

        <!-- Page Heading -->
        <header
            class="border-b border-zinc-800 bg-zinc-900/50"
            v-if="$slots.header"
        >
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <slot name="header" />
            </div>
        </header>

        <!-- Page Content -->
        <main class="pb-16 sm:pb-0">
            <slot />
        </main>

        <!-- Mobile bottom navigation -->
        <MobileTabBar
            v-if="authUser"
            :unread-activity="unreadCount"
            :unread-hostility="unreadHostilityCount"
            @open-more="showingMoreDrawer = true"
        />

        <MobileMoreDrawer
            v-if="authUser"
            :open="showingMoreDrawer"
            :user-name="authUser?.name ?? null"
            :user-email="authUser?.email ?? null"
            :unread-hostility="unreadHostilityCount"
            @close="showingMoreDrawer = false"
        />

        <!-- Global overlays -->
        <ToastContainer />

        <ToolboxDock v-if="authUser" />

        <ClaimUsernameModal v-if="requiresClaim" />

        <BrokenItemModal
            v-if="brokenItemKey"
            :broken-item-key="brokenItemKey"
            :item-name="brokenItem?.name ?? null"
            :repair-cost="brokenItem?.repair_cost_barrels ?? null"
            :player-barrels="brokenItem?.player_barrels ?? null"
        />
    </div>
</template>
