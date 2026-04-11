<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-mono text-xl font-bold uppercase tracking-wider text-amber-400">
                Profile
            </h2>
        </template>

        <div class="py-6 sm:py-12">
            <div class="mx-auto max-w-7xl space-y-4 sm:space-y-6 px-3 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-zinc-800 bg-zinc-900 p-4 sm:p-8 shadow">
                    <UpdateProfileInformationForm
                        :must-verify-email="mustVerifyEmail"
                        :status="status"
                        class="max-w-xl"
                    />
                </div>

                <div class="rounded-lg border border-zinc-800 bg-zinc-900 p-4 sm:p-8 shadow">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="rounded-lg border border-zinc-800 bg-zinc-900 p-4 sm:p-8 shadow">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
