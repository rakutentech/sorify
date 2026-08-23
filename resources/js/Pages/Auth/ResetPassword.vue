<script setup>
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Card, TextField, Button, LanguageSwitcher } from '@/Components/ui';
import sorifyLogo from '@/../images/sorify-icon.svg';
import { Sun, Moon, Mail, Lock, KeyRound } from '@lucide/vue';

const props = defineProps({
    token: String,
    email: String,
});

const { t } = useI18n();
const { theme, toggleTheme } = useTheme();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/sorify/reset-password', {
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
                <p class="mt-2 md-body-medium text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center gap-2"><KeyRound :size="16" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('auth.chooseNewPasswordSubtitle') }}</p>
            </div>

            <!-- Card -->
            <Card variant="elevated" padding="p-6">
                <form @submit.prevent="submit" class="space-y-5">
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
                        :label="t('auth.newPassword')"
                        type="password"
                        autocomplete="new-password"
                        required
                        :error="form.errors.password"
                    >
                        <template #leading><Lock :size="16" /></template>
                    </TextField>

                    <TextField
                        v-model="form.password_confirmation"
                        :label="t('auth.confirmNewPassword')"
                        type="password"
                        autocomplete="new-password"
                        required
                    >
                        <template #leading><Lock :size="16" /></template>
                    </TextField>

                    <!-- Submit -->
                    <Button type="submit" variant="filled" :disabled="form.processing" class="w-full">
                        <span v-if="form.processing">{{ t('auth.resetting') }}</span>
                        <span v-else>{{ t('auth.resetPassword') }}</span>
                    </Button>
                </form>
            </Card>
        </div>
    </div>
</template>
