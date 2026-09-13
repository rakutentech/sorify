<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Avatar } from '@/Components/ui';
import AgentProfiles from '@/Components/Agent/AgentProfiles.vue';
import { UserCircle, Upload, KeyRound, Lock, User, Check, Bot } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    user: Object,
    agentProfiles: { type: Array, default: () => [] },
});

// Section navigation. Selecting an item shows only its card.
const sections = computed(() => [
    { id: 'avatar', label: t('profile.avatar'), icon: UserCircle },
    { id: 'account', label: t('profile.account'), icon: User },
    { id: 'agents', label: t('agent.profiles.navTitle'), icon: Bot },
    { id: 'password', label: hasPassword.value ? t('profile.changePassword') : t('profile.setPassword'), icon: KeyRound },
]);

const activeSection = ref(
    ['avatar', 'account', 'agents', 'password'].includes(new URLSearchParams(window.location.search).get('section'))
        ? new URLSearchParams(window.location.search).get('section')
        : 'avatar',
);

function select(id) {
    if (activeSection.value === id) return;

    activeSection.value = id;
    syncSectionQueryParam();
}

// Mirror the open section into the URL query (?section=agents) so it can be
// deep-linked and shared (the agent drawer's settings button uses this).
// preserveState keeps form state intact; replace avoids polluting history.
function syncSectionQueryParam() {
    const params = new URLSearchParams(window.location.search);

    if (activeSection.value && activeSection.value !== 'avatar') {
        params.set('section', activeSection.value);
    } else {
        params.delete('section');
    }

    const query = params.toString();

    router.get(
        window.location.pathname + (query ? `?${query}` : ''),
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

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

        <div class="w-[1000px] max-w-full mx-auto">
            <h1 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5 mb-6"><UserCircle :size="26" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('profile.title') }}</h1>

            <div class="grid grid-cols-1 lg:grid-cols-[220px_760px] gap-6 items-start">
                <!-- Section sidebar -->
                <nav class="lg:sticky lg:top-6 order-2 lg:order-1">
                    <Card padding="p-2" variant="outlined">
                        <ul class="flex lg:flex-col gap-1 overflow-x-auto">
                            <li v-for="section in sections" :key="section.id">
                                <button
                                    type="button"
                                    class="w-full flex items-center gap-2.5 px-3 py-2 rounded-[var(--md-sys-shape-corner-small)] md-label-large transition-colors text-left whitespace-nowrap"
                                    :class="activeSection === section.id
                                        ? 'bg-[color-mix(in_srgb,var(--md-sys-color-primary)_12%,transparent)] text-[var(--md-sys-color-primary)]'
                                        : 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] hover:text-[var(--md-sys-color-on-surface)]'"
                                    @click="select(section.id)"
                                >
                                    <component :is="section.icon" :size="18" />
                                    <span>{{ section.label }}</span>
                                </button>
                            </li>
                        </ul>
                    </Card>
                </nav>

                <!-- Sections -->
                <div class="space-y-6 order-1 lg:order-2">

                    <!-- Avatar -->
                    <div v-if="activeSection === 'avatar'" data-section="avatar">
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
                    </div>

                    <!-- Account -->
                    <div v-if="activeSection === 'account'" data-section="account">
                        <Card padding="p-6">
                            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4 flex items-center gap-2"><User :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('profile.account') }}</h2>

                            <form @submit.prevent="submitName" class="space-y-4">
                                <!-- Read-only account info -->
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 mb-2 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <span class="flex items-center gap-1.5">
                                        <span class="text-[var(--md-sys-color-on-surface)]">{{ t('profile.email') }}:</span>
                                        <span>{{ user.email }}</span>
                                    </span>
                                    <span class="flex items-center gap-1.5">
                                        <span class="text-[var(--md-sys-color-on-surface)]">{{ t('profile.role') }}:</span>
                                        <span>{{ roleOf(user) }}</span>
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 gap-4">
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
                                </div>

                                <Button type="submit" variant="filled" :disabled="nameForm.processing">
                                    <template #leading><Check :size="16" /></template>
                                    <span v-if="nameForm.processing">{{ t('profile.updating') }}</span>
                                    <span v-else>{{ t('profile.updateName') }}</span>
                                </Button>
                            </form>
                        </Card>
                    </div>

                    <!-- AI Agent Profiles -->
                    <div v-if="activeSection === 'agents'" data-section="agents">
                        <AgentProfiles :profiles="agentProfiles" />
                    </div>

                    <!-- Change / Set Password -->
                    <div v-if="activeSection === 'password'" data-section="password">
                        <Card padding="p-6">
                            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-4 flex items-center gap-2"><KeyRound :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ hasPassword ? t('profile.changePassword') : t('profile.setPassword') }}</h2>
                            <p v-if="!hasPassword" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-4">
                                {{ t('profile.setPasswordHint') }}
                            </p>

                            <form @submit.prevent="submitPassword" class="space-y-4">
                                <div class="space-y-4">
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

                </div>
            </div>
        </div>
    </AppLayout>
</template>
