<script setup>
import { useForm } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Card, TextField, Button } from '@/Components/ui';
import sorifyLogo from '@/../images/sorify-icon.svg';

const { theme, toggleTheme } = useTheme();

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
                <img :src="sorifyLogo" alt="Sorify" class="h-14 mx-auto rounded-lg px-3 py-2" />
                <p class="mt-2 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">Create your account</p>
            </div>

            <!-- Card -->
            <Card variant="elevated" padding="p-6">
                <form @submit.prevent="submit" class="space-y-5">
                    <TextField
                        v-model="form.name"
                        label="Name"
                        type="text"
                        autocomplete="name"
                        required
                        :error="form.errors.name"
                    />

                    <TextField
                        v-model="form.email"
                        label="Email"
                        type="email"
                        autocomplete="email"
                        required
                        :error="form.errors.email"
                    />

                    <TextField
                        v-model="form.password"
                        label="Password"
                        type="password"
                        autocomplete="new-password"
                        required
                        :error="form.errors.password"
                    />

                    <TextField
                        v-model="form.password_confirmation"
                        label="Confirm password"
                        type="password"
                        autocomplete="new-password"
                        required
                        :error="form.errors.password_confirmation"
                    />

                    <!-- Submit -->
                    <Button type="submit" variant="filled" :disabled="form.processing" class="w-full">
                        <span v-if="form.processing">Creating account…</span>
                        <span v-else>Sign up</span>
                    </Button>
                </form>

                <p class="mt-5 text-center md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    Already have an account?
                    <a href="/sorify/login" class="text-[var(--md-sys-color-primary)] hover:underline">Sign in</a>
                </p>
            </Card>
        </div>
    </div>
</template>
