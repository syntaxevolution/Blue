<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

defineProps<{
    name_max: number;
    tag_max: number;
    motto_max: number;
    creation_cost: number;
}>();

const form = useForm<{ name: string; tag: string; motto: string }>({
    name: '',
    tag: '',
    motto: '',
});

function submit() {
    form.post(route('mdn.store'));
}
</script>

<template>
    <Head title="Create MDN" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-black uppercase tracking-widest text-amber-400">
                Create MDN
            </h2>
        </template>

        <div class="py-4 sm:py-8">
            <div class="mx-auto max-w-lg space-y-4 sm:space-y-6 px-3 sm:px-6 lg:px-8">
                <p class="text-sm text-zinc-400">
                    Founding an MDN costs <span class="font-mono text-amber-300">A{{ creation_cost.toFixed(2) }}</span>
                    and immediately appoints you as leader.
                </p>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="mdn-name" class="mb-1 block text-xs uppercase tracking-widest text-zinc-400">
                            Name (max {{ name_max }})
                        </label>
                        <input
                            id="mdn-name"
                            v-model="form.name"
                            type="text"
                            :maxlength="name_max"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-zinc-100"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-rose-400">
                            {{ form.errors.name }}
                        </p>
                    </div>
                    <div>
                        <label for="mdn-tag" class="mb-1 block text-xs uppercase tracking-widest text-zinc-400">
                            Tag (max {{ tag_max }}, alphanumeric)
                        </label>
                        <input
                            id="mdn-tag"
                            v-model="form.tag"
                            type="text"
                            :maxlength="tag_max"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 font-mono uppercase text-amber-300"
                        />
                        <p v-if="form.errors.tag" class="mt-1 text-xs text-rose-400">
                            {{ form.errors.tag }}
                        </p>
                    </div>
                    <div>
                        <label for="mdn-motto" class="mb-1 block text-xs uppercase tracking-widest text-zinc-400">
                            Motto (optional, max {{ motto_max }})
                        </label>
                        <textarea
                            id="mdn-motto"
                            v-model="form.motto"
                            :maxlength="motto_max"
                            rows="2"
                            class="w-full rounded border border-zinc-700 bg-zinc-900 px-3 py-2 text-sm text-zinc-100"
                        ></textarea>
                    </div>

                    <div
                        v-if="form.errors.mdn"
                        class="rounded border border-rose-700 bg-rose-900/40 px-3 py-2 text-xs text-rose-200"
                    >
                        {{ form.errors.mdn }}
                    </div>

                    <div class="flex items-center justify-between">
                        <Link
                            :href="route('mdn.index')"
                            class="text-sm text-zinc-400 hover:text-amber-400"
                        >
                            ← Back
                        </Link>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded bg-amber-500 px-4 py-2 font-mono text-sm font-bold uppercase tracking-widest text-zinc-950 hover:bg-amber-400 disabled:opacity-50"
                        >
                            Found MDN
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
