<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField } from '@/Components/ui';

defineProps({
    user: Object,
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put('/sorify/profile', {
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Profile" />

        <div class="max-w-4xl mx-auto space-y-6">
            <h1 class="md-title-large text-[var(--md-sys-color-on-surface)]">Profile</h1>

            <!-- Change Password -->
            <Card padding="p-6">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4">Change Password</h2>

                <form @submit.prevent="submitPassword" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <TextField
                            v-model="passwordForm.current_password"
                            label="Current Password"
                            type="password"
                            autocomplete="current-password"
                            required
                            :error="passwordForm.errors.current_password"
                        />
                        <TextField
                            v-model="passwordForm.password"
                            label="New Password"
                            type="password"
                            autocomplete="new-password"
                            required
                            :error="passwordForm.errors.password"
                        />
                        <TextField
                            v-model="passwordForm.password_confirmation"
                            label="Confirm New Password"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </div>

                    <Button type="submit" variant="filled" :disabled="passwordForm.processing">
                        <span v-if="passwordForm.processing">Updating…</span>
                        <span v-else>Update Password</span>
                    </Button>
                </form>
            </Card>

        </div>
    </AppLayout>
</template>
