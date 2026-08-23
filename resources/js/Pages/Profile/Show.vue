<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Avatar } from '@/Components/ui';
import { UserCircle, Upload, KeyRound, Lock, User, Check } from '@lucide/vue';

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

// Password form. When the user has no password yet (OAuth-only account),
// they set an initial password — no current password required.
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const hasPassword = computed(() => !!props.user.has_password);

function submitPassword() {
    passwordForm.put('/sorify/profile/password', {
        onSuccess: () => passwordForm.reset(),
    });
}

// Avatar upload. Inertia supports file uploads via a normal form post.
const avatarForm = useForm({
    avatar: null,
});

function onAvatarChange(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    avatarForm.avatar = file;
    avatarForm.post('/sorify/profile/avatar', {
        preserveScroll: true,
        onSuccess: () => { avatarForm.reset(); e.target.value = ''; },
    });
}

function removeAvatar() {
    if (!confirm(t('profile.confirmRemoveAvatar'))) return;
    avatarForm.delete('/sorify/profile/avatar', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('profile.title')" />

        <div class="max-w-4xl mx-auto space-y-6">
            <h1 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5"><UserCircle :size="26" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('profile.title') }}</h1>

            <!-- Avatar -->
            <Card padding="p-6">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4 flex items-center gap-2"><UserCircle :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('profile.avatar') }}</h2>
                <div class="flex items-center gap-5">
                    <Avatar
                        :name="user.name"
                        :email="user.email"
                        :avatar-url="user.avatar_url"
                        size="md"
                    />
                    <div class="flex items-center gap-3">
                        <label class="inline-flex items-center justify-center gap-2 h-10 px-6 text-sm rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-[background-color,box-shadow,filter] duration-150 bg-transparent text-[var(--md-sys-color-primary)] border border-[var(--md-sys-color-outline)] hover:bg-[color-mix(in_srgb,var(--md-sys-color-primary)_8%,transparent)] cursor-pointer">
                            <Upload :size="16" />
                            <span>{{ t('profile.uploadAvatar') }}</span>
                            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" class="hidden" @change="onAvatarChange" :disabled="avatarForm.processing" />
                        </label>
                        <Button v-if="user.avatar_url" variant="text" :disabled="avatarForm.processing" @click="removeAvatar">
                            {{ t('profile.removeAvatar') }}
                        </Button>
                        <span v-if="avatarForm.processing" class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('profile.uploading') }}</span>
                        <span v-if="avatarForm.errors.avatar" class="md-label-small text-[var(--md-sys-color-error)]">{{ avatarForm.errors.avatar }}</span>
                    </div>
                </div>
                <p class="mt-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('profile.avatarHint') }}</p>
            </Card>

            <!-- Account -->
            <Card padding="p-6">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4 flex items-center gap-2"><User :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('profile.account') }}</h2>

                <form @submit.prevent="submitName" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <TextField
                            v-model="nameForm.name"
                            :label="t('profile.name')"
                            type="text"
                            autocomplete="name"
                            required
                            :error="nameForm.errors.name"
                        >
                            <template #leading><User :size="16" /></template>
                        </TextField>
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
                        <template #leading><Check :size="16" /></template>
                        <span v-if="nameForm.processing">{{ t('profile.updating') }}</span>
                        <span v-else>{{ t('profile.updateName') }}</span>
                    </Button>
                </form>
            </Card>

            <!-- Change / Set Password -->
            <Card padding="p-6">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-1 flex items-center gap-2"><KeyRound :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ hasPassword ? t('profile.changePassword') : t('profile.setPassword') }}</h2>
                <p v-if="!hasPassword" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-4">
                    {{ t('profile.setPasswordHint') }}
                </p>

                <form @submit.prevent="submitPassword" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <TextField
                            v-if="hasPassword"
                            v-model="passwordForm.current_password"
                            :label="t('profile.currentPassword')"
                            type="password"
                            autocomplete="current-password"
                            required
                            :error="passwordForm.errors.current_password"
                        >
                            <template #leading><Lock :size="16" /></template>
                        </TextField>
                        <TextField
                            v-model="passwordForm.password"
                            :label="t('profile.newPassword')"
                            type="password"
                            autocomplete="new-password"
                            required
                            :error="passwordForm.errors.password"
                        >
                            <template #leading><Lock :size="16" /></template>
                        </TextField>
                        <TextField
                            v-model="passwordForm.password_confirmation"
                            :label="t('profile.confirmNewPassword')"
                            type="password"
                            autocomplete="new-password"
                            required
                            :error="passwordForm.errors.password_confirmation"
                        >
                            <template #leading><Lock :size="16" /></template>
                        </TextField>
                    </div>

                    <Button type="submit" variant="filled" :disabled="passwordForm.processing">
                        <template #leading><KeyRound :size="16" /></template>
                        <span v-if="passwordForm.processing">{{ t('profile.updating') }}</span>
                        <span v-else>{{ t('profile.updatePassword') }}</span>
                    </Button>
                </form>
            </Card>

        </div>
    </AppLayout>
</template>
