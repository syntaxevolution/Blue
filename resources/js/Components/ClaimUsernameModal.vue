<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    name: '',
});

const touched = ref(false);

function submit() {
    touched.value = true;
    form.post(route('username.claim'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="fixed inset-0 z-40 flex items-center justify-center bg-zinc-950/90 backdrop-blur">
        <div class="w-full max-w-md rounded-lg border border-amber-600/40 bg-zinc-900 p-6 shadow-2xl">
            <h2 class="font-mono text-xl font-bold uppercase tracking-widest text-amber-400 mb-2">
                Claim your handle
            </h2>
            <p class="text-zinc-400 text-sm mb-5 leading-relaxed">
                This is how other players will see you. Alphanumeric only,
                5–15 characters. <span class="text-amber-400">Once claimed, it cannot be changed.</span>
            </p>

            <form @submit.prevent="submit" class="space-y-3">
                <input
                    v-model="form.name"
                    type="text"
                    maxlength="15"
                    minlength="5"
                    pattern="[A-Za-z0-9]{5,15}"
                    class="w-full rounded-md border border-zinc-700 bg-zinc-950 px-3 py-2 font-mono text-zinc-100 focus:border-amber-400 focus:outline-none"
                    placeholder="e.g. DustBaron"
                    autocomplete="off"
                    required
                />

                <div
                    v-if="form.errors.name"
                    class="text-rose-400 text-xs font-mono"
                >
                    {{ form.errors.name }}
                </div>

                <button
                    type="submit"
                    class="w-full rounded-md bg-amber-500 px-4 py-3 font-mono text-sm font-bold uppercase tracking-wider text-zinc-950 hover:bg-amber-400 transition disabled:opacity-50"
                    :disabled="form.processing || form.name.length < 5"
                >
                    Claim
                </button>
            </form>
        </div>
    </div>
</template>
