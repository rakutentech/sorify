<script setup>
import { computed } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Alert, LanguageSwitcher } from '@/Components/ui';
import sorifyLogo from '@/../images/sorify-icon.svg';

const { t } = useI18n();
const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { theme, toggleTheme } = useTheme();
const user = computed(() => page.props.auth?.user ?? null);

const navLinks = computed(() => [
    { label: t('nav.dashboard'), href: '/sorify/' },
    { label: t('nav.runs'), href: '/sorify/runs' },
    { label: t('nav.testSuites'), href: '/sorify/suites' },
]);

const docsLink = computed(() => ({ label: t('nav.docs'), href: 'https://github.com/rakutentech/sorify', external: true, newTab: true }));

const adminLinks = computed(() => user.value?.is_admin ? [
    { label: t('nav.admin'), href: '/sorify/admin/users' },
    { label: t('nav.logs'), href: '/sorify/log-viewer', external: true, newTab: true },
] : []);

function isActive(href) {
    const url = page.url;
    if (href === '/sorify/') return url === '/sorify/' || url === '/sorify';
    return url.startsWith(href);
}

function logout() {
    router.post('/sorify/logout');
}
</script>

<template>
    <div class="min-h-screen bg-[var(--md-sys-color-surface)] text-[var(--md-sys-color-on-surface)] flex flex-col">
        <!-- Top App Bar -->
        <nav class="bg-[var(--md-sys-color-surface-container)] flex-shrink-0">
            <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <!-- Brand -->
                    <div class="flex items-center gap-8">
                        <Link href="/sorify/" class="flex items-center">
                            <img :src="sorifyLogo" alt="Sorify" class="h-11 rounded-md px-1 py-1" />
                        </Link>

                        <!-- Nav Links -->
                        <div class="hidden sm:flex items-center gap-1">
                            <Link
                                v-for="link in navLinks"
                                :key="link.href"
                                :href="link.href"
                                :class="[
                                    'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors flex items-center gap-1.5',
                                    isActive(link.href)
                                        ? 'bg-green-600 text-white'
                                        : 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]',
                                    link.href === '/sorify/suites' ? 'font-bold' : ''
                                ]"
                            >
                                <svg v-if="link.href === '/sorify/suites'" class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
                                </svg>
                                {{ link.label }}
                            </Link>

                            <a
                                :href="docsLink.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] flex items-center gap-1.5"
                            >
                                {{ docsLink.label }}
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6v6M20 4 10 14" />
                                </svg>
                            </a>

                            <!-- Admin-only links, visually grouped -->
                            <div
                                v-if="adminLinks.length"
                                class="flex items-center gap-1 ml-1 pl-1 rounded-[var(--md-sys-shape-corner-full)] bg-[var(--md-sys-color-tertiary)]/15 border border-[var(--md-sys-color-tertiary)]/30"
                            >
                                <template v-for="link in adminLinks" :key="link.href">
                                    <a
                                        v-if="link.external"
                                        :href="link.href"
                                        :target="link.newTab ? '_blank' : undefined"
                                        :rel="link.newTab ? 'noopener noreferrer' : undefined"
                                        class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20"
                                    >
                                        {{ link.label }}
                                    </a>
                                    <Link
                                        v-else
                                        :href="link.href"
                                        :class="[
                                            'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20',
                                            isActive(link.href) && 'bg-[var(--md-sys-color-tertiary)]/30'
                                        ]"
                                    >
                                        {{ link.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Right side -->
                    <div class="flex items-center gap-3">
                        <!-- User nav -->
                        <template v-if="user">
                            <Link
                                href="/sorify/profile"
                                class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                            >
                                {{ user.name }}
                            </Link>
                            <button
                                @click="logout"
                                class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                            >
                                {{ t('nav.logout') }}
                            </button>
                        </template>
                        <span v-else class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('nav.qaPlatform') }}</span>

                        <!-- Language switcher -->
                        <LanguageSwitcher />

                        <!-- Theme toggle -->
                        <IconButton
                            variant="standard"
                            :label="theme === 'dark' ? t('nav.switchToLight') : t('nav.switchToDark')"
                            @click="toggleTheme"
                        >
                            <!-- Sun icon (shown in dark mode) -->
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
                            <!-- Moon icon (shown in light mode) -->
                            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                            </svg>
                        </IconButton>
                    </div>
                </div>
            </div>

            <!-- Mobile nav -->
            <div class="sm:hidden border-t border-[var(--md-sys-color-outline-variant)] px-4 py-2 flex gap-1 overflow-x-auto">
                <Link
                    v-for="link in navLinks"
                    :key="link.href"
                    :href="link.href"
                    :class="[
                        'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors',
                        isActive(link.href)
                            ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)]'
                            : 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]'
                    ]"
                >
                    {{ link.label }}
                </Link>

                <a
                    :href="docsLink.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]"
                >
                    {{ docsLink.label }}
                </a>

                <div
                    v-if="adminLinks.length"
                    class="flex items-center gap-1 ml-1 pl-1 rounded-[var(--md-sys-shape-corner-full)] bg-[var(--md-sys-color-tertiary)]/15 border border-[var(--md-sys-color-tertiary)]/30"
                >
                    <template v-for="link in adminLinks" :key="link.href">
                        <a
                            v-if="link.external"
                            :href="link.href"
                            :target="link.newTab ? '_blank' : undefined"
                            :rel="link.newTab ? 'noopener noreferrer' : undefined"
                            class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20"
                        >
                            {{ link.label }}
                        </a>
                        <Link
                            v-else
                            :href="link.href"
                            :class="[
                                'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20',
                                isActive(link.href) && 'bg-[var(--md-sys-color-tertiary)]/30'
                            ]"
                        >
                            {{ link.label }}
                        </Link>
                    </template>
                </div>

                <LanguageSwitcher />
            </div>
        </nav>

        <!-- Flash Messages -->
        <div v-if="flash.success || flash.error" class="max-w-screen-2xl mx-auto w-full px-4 sm:px-6 lg:px-8 pt-4 space-y-2">
            <Alert v-if="flash.success" tone="success">
                <template #icon>
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </template>
                {{ flash.success }}
            </Alert>
            <Alert v-if="flash.error" tone="error">
                <template #icon>
                    <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1-9v4a1 1 0 002 0V9a1 1 0 00-2 0zm0-4a1 1 0 112 0 1 1 0 01-2 0z" clip-rule="evenodd"/>
                    </svg>
                </template>
                {{ flash.error }}
            </Alert>
        </div>

        <!-- Page Content -->
        <main class="flex-1 min-h-0 max-w-screen-2xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 flex flex-col">
            <slot />
        </main>
    </div>
</template>
