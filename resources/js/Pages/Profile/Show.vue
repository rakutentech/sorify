<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField } from '@/Components/ui';

const { t } = useI18n();

const props = defineProps({
    user: Object,
});

function roleOf(user) {
    if (user.is_admin) return t('profile.roleAdmin');
    if (user.is_view_only) return t('profile.roleViewer');
    return t('profile.roleMember');
}

const nameForm = useForm({
    name: props.user.name,
});

function submitName() {
    nameForm.put('/sorify/profile');
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put('/sorify/profile/password', {
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('profile.title')" />

        <div class="max-w-4xl mx-auto space-y-6">
            <h1 class="md-title-large text-[var(--md-sys-color-on-surface)]">{{ t('profile.title') }}</h1>

            <!-- Account -->
            <Card padding="p-6">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4">{{ t('profile.account') }}</h2>

                <form @submit.prevent="submitName" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <TextField
                            v-model="nameForm.name"
                            :label="t('profile.name')"
                            type="text"
                            autocomplete="name"
                            required
                            :error="nameForm.errors.name"
                        />
                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">
                                {{ t('profile.email') }}
                            </label>
                            <p class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] text-[var(--md-sys-color-on-surface-variant)] md-body-medium">
                                {{ user.email }}
                            </p>
                        </div>
                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">
                                {{ t('profile.role') }}
                            </label>
                            <p class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] text-[var(--md-sys-color-on-surface-variant)] md-body-medium">
                                {{ roleOf(user) }}
                            </p>
                        </div>
                    </div>

                    <Button type="submit" variant="filled" :disabled="nameForm.processing">
                        <span v-if="nameForm.processing">{{ t('profile.updating') }}</span>
                        <span v-else>{{ t('profile.updateName') }}</span>
                    </Button>
                </form>
            </Card>

            <!-- Change Password -->
            <Card padding="p-6">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4">{{ t('profile.changePassword') }}</h2>

                <form @submit.prevent="submitPassword" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <TextField
                            v-model="passwordForm.current_password"
                            :label="t('profile.currentPassword')"
                            type="password"
                            autocomplete="current-password"
                            required
                            :error="passwordForm.errors.current_password"
                        />
                        <TextField
                            v-model="passwordForm.password"
                            :label="t('profile.newPassword')"
                            type="password"
                            autocomplete="new-password"
                            required
                            :error="passwordForm.errors.password"
                        />
                        <TextField
                            v-model="passwordForm.password_confirmation"
                            :label="t('profile.confirmNewPassword')"
                            type="password"
                            autocomplete="new-password"
                            required
                            :error="passwordForm.errors.password_confirmation"
                        />
                    </div>

                    <Button type="submit" variant="filled" :disabled="passwordForm.processing">
                        <span v-if="passwordForm.processing">{{ t('profile.updating') }}</span>
                        <span v-else>{{ t('profile.updatePassword') }}</span>
                    </Button>
                </form>
            </Card>

        </div>
    </AppLayout>
</template>
