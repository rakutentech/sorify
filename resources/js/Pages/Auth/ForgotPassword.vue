<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Card, TextField, Button, Alert, LanguageSwitcher } from '@/Components/ui';
import sorifyLogo from '@/../images/sorify-icon.svg';
import { Sun, Moon, Mail, KeyRound } from '@lucide/vue';

const { t } = useI18n();
const { theme, toggleTheme } = useTheme();
const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({
    email: '',
});

function submit() {
    form.post('/sorify/forgot-password');
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
                <p class="mt-2 md-body-medium text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center gap-2"><KeyRound :size="16" :style="{ color: 'var(--md-sys-color-primary)' }" />{{ t('auth.resetPasswordSubtitle') }}</p>
            </div>

            <!-- Card -->
            <Card variant="elevated" padding="p-6">
                <Alert v-if="flash.success" tone="success" class="mb-5">
                    {{ flash.success }}
                </Alert>

                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mb-5">
                    {{ t('auth.forgotPasswordInstructions') }}
                </p>

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

                    <!-- Submit -->
                    <Button type="submit" variant="filled" :disabled="form.processing" class="w-full">
                        <span v-if="form.processing">{{ t('auth.sending') }}</span>
                        <span v-else>{{ t('auth.sendResetLink') }}</span>
                    </Button>

                    <a href="/sorify/login" class="block text-center md-label-small text-[var(--md-sys-color-primary)] hover:underline">
                        {{ t('auth.backToSignIn') }}
                    </a>
                </form>
            </Card>
        </div>
    </div>
</template>
