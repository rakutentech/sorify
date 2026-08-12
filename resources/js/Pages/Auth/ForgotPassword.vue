<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Card, TextField, Button, Alert } from '@/Components/ui';

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
        <!-- Theme toggle -->
        <IconButton
            class="fixed top-4 right-4"
            variant="standard"
            :label="theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
            @click="toggleTheme"
        >
            <svg v-if="theme === 'dark'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <circle cx="12" cy="12" r="5"/>
                <line x1="12" y1="1" x2="12" y2="3"/>
                <line x1="12" y1="21" x2="12" y2="23"/>
                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                <line x1="1" y1="12" x2="3" y2="12"/>
                <line x1="21" y1="12" x2="23" y2="12"/>
                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </IconButton>

        <div class="w-full max-w-sm">
            <!-- Brand -->
            <div class="text-center mb-8">
                <div class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center justify-center gap-2">
                    <span class="text-[var(--md-sys-color-primary)]">⬡</span>
                    Sorify
                </div>
                <p class="mt-2 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">Reset your password</p>
            </div>

            <!-- Card -->
            <Card variant="elevated" padding="p-6">
                <Alert v-if="flash.success" tone="success" class="mb-5">
                    {{ flash.success }}
                </Alert>

                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mb-5">
                    Enter your email address and we'll send you a link to reset your password.
                </p>

                <form @submit.prevent="submit" class="space-y-5">
                    <TextField
                        v-model="form.email"
                        label="Email"
                        type="email"
                        autocomplete="email"
                        required
                        :error="form.errors.email"
                    />

                    <!-- Submit -->
                    <Button type="submit" variant="filled" :disabled="form.processing" class="w-full">
                        <span v-if="form.processing">Sending…</span>
                        <span v-else>Send reset link</span>
                    </Button>

                    <a href="/sorify/login" class="block text-center md-label-small text-[var(--md-sys-color-primary)] hover:underline">
                        Back to sign in
                    </a>
                </form>
            </Card>
        </div>
    </div>
</template>
