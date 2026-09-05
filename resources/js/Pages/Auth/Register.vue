<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Card, TextField, Button, LanguageSwitcher } from '@/Components/ui';
import sorifyLogo from '@/../images/sorify-icon.svg';
import { Sun, Moon, Mail, Lock, User, UserPlus } from '@lucide/vue';

const { t } = useI18n();
const { theme, toggleTheme } = useTheme();

const props = defineProps({
    githubApps: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/sorify/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <div class="min-h-screen bg-[var(--md-sys-color-surface)] flex items-center justify-center px-4">
        <!-- Top-right controls -->
        <div class="fixed top-4 right-4 flex items-center gap-2">
            <LanguageSwitcher />
            <IconButton
                variant="standard"
                :label="theme === 'dark' ? t('nav.switchToLight') : t('nav.switchToDark')"
                @click="toggleTheme"
            >
            <Sun v-if="theme === 'dark'" :size="16" :style="{ color: 'var(--md-ext-color-warning)' }" />
            <Moon v-else :size="16" :style="{ color: 'var(--md-sys-color-primary)' }" />
        </IconButton>
        </div>

        <div class="w-full max-w-sm">
            <!-- Brand -->
            <div class="text-center mb-8">
                <img :src="sorifyLogo" alt="Sorify" class="h-20 mx-auto rounded-lg px-2 py-1" />
                <p class="mt-2 md-body-medium text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center gap-2"><UserPlus :size="16" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('auth.createAccountSubtitle') }}</p>
            </div>

            <!-- Card -->
            <Card variant="elevated" padding="p-6">
                <form @submit.prevent="submit" class="space-y-5">
                    <TextField
                        v-model="form.name"
                        :label="t('auth.name')"
                        type="text"
                        autocomplete="name"
                        required
                        :error="form.errors.name"
                    >
                        <template #leading><User :size="16" /></template>
                    </TextField>

                    <TextField
                        v-model="form.email"
                        :label="t('auth.email')"
                        type="email"
                        autocomplete="email"
                        required
                        :error="form.errors.email"
                    >
                        <template #leading><Mail :size="16" /></template>
                    </TextField>

                    <TextField
                        v-model="form.password"
                        :label="t('auth.password')"
                        type="password"
                        autocomplete="new-password"
                        required
                        :error="form.errors.password"
                    >
                        <template #leading><Lock :size="16" /></template>
                    </TextField>

                    <TextField
                        v-model="form.password_confirmation"
                        :label="t('auth.confirmPassword')"
                        type="password"
                        autocomplete="new-password"
                        required
                        :error="form.errors.password_confirmation"
                    >
                        <template #leading><Lock :size="16" /></template>
                    </TextField>

                    <!-- Submit -->
                    <Button type="submit" variant="filled" :disabled="form.processing" class="w-full">
                        <span v-if="form.processing">{{ t('auth.creatingAccount') }}</span>
                        <span v-else>{{ t('auth.signUp') }}</span>
                    </Button>

                    <!-- Divider (only when a GitHub app is configured) -->
                    <div v-if="githubApps.length" class="relative py-1">
                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                            <div class="w-full border-t border-[var(--md-sys-color-outline-variant)]"></div>
                        </div>
                        <div class="relative flex justify-center">
                            <span class="px-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-low)]">
                                {{ t('auth.orContinueWith') }}
                            </span>
                        </div>
                    </div>

                    <!-- GitHub OAuth — one button per configured GitHub app -->
                    <a
                        v-for="app in githubApps"
                        :key="app.id"
                        :href="`/sorify/auth/github/redirect?app=${app.id}`"
                        class="flex items-center justify-center gap-2 h-10 w-full rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-[background-color,box-shadow,filter] duration-150 bg-transparent text-[var(--md-sys-color-on-surface)] border border-[var(--md-sys-color-outline)] hover:bg-[color-mix(in_srgb,var(--md-sys-color-on-surface)_8%,transparent)]"
                        :class="githubApps.length > 1 ? 'mt-2 first:mt-0' : ''"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 .5C5.73.5.5 5.73.5 12c0 5.08 3.29 9.39 7.86 10.91.58.11.79-.25.79-.56 0-.27-.01-1-.02-1.96-3.2.7-3.88-1.54-3.88-1.54-.52-1.33-1.28-1.69-1.28-1.69-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.7 1.26 3.36.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11.05 11.05 0 0 1 5.79 0c2.21-1.49 3.18-1.18 3.18-1.18.63 1.59.23 2.76.11 3.05.74.81 1.19 1.84 1.19 3.1 0 4.42-2.69 5.39-5.25 5.68.41.36.78 1.07.78 2.16 0 1.56-.01 2.82-.01 3.2 0 .31.21.68.8.56A11.51 11.51 0 0 0 23.5 12C23.5 5.73 18.27.5 12 .5z"/>
                        </svg>
                        {{ t('auth.signUpWithApp', { name: app.name }) }}
                    </a>
                </form>

                <p class="mt-5 text-center md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    {{ t('auth.haveAccount') }}
                    <a href="/sorify/login" class="text-[var(--md-sys-color-primary)] hover:underline">{{ t('auth.signIn') }}</a>
                </p>
            </Card>
        </div>
    </div>
</template>
