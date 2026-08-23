<script setup>
import { computed, ref, onBeforeUnmount } from 'vue';
import { usePage, Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useTheme } from '@/composables/useTheme.js';
import { IconButton, Alert, LanguageSwitcher, Avatar } from '@/Components/ui';
import {
    LayoutDashboard, Activity, FolderKanban, Star, BookOpen,
    ShieldCheck, ScrollText, ExternalLink, ChevronDown, Sun, Moon,
    UserCircle, LogOut, CircleCheck, Info,
} from '@lucide/vue';
import sorifyLogo from '@/../images/sorify-icon.svg';

const { t } = useI18n();
const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const { theme, toggleTheme } = useTheme();
const user = computed(() => page.props.auth?.user ?? null);

const navLinks = computed(() => [
    { label: t('nav.dashboard'), href: '/sorify/', icon: LayoutDashboard, accent: 'var(--md-sys-color-primary)' },
    { label: t('nav.runs'), href: '/sorify/runs', icon: Activity, accent: 'var(--md-ext-color-success)' },
    { label: t('nav.testSuites'), href: '/sorify/suites', icon: FolderKanban, accent: 'var(--md-sys-color-tertiary)' },
    { label: t('nav.bookmarks'), href: '/sorify/bookmarks', icon: Star, accent: 'var(--md-ext-color-warning)' },
]);

const docsLink = computed(() => ({ label: t('nav.docs'), href: 'https://github.com/rakutentech/sorify', external: true, newTab: true, icon: BookOpen, accent: 'var(--md-sys-color-on-surface-variant)' }));

const adminLinks = computed(() => user.value?.is_admin ? [
    { label: t('nav.admin'), href: '/sorify/admin/users', icon: ShieldCheck, accent: 'var(--md-sys-color-error)' },
    { label: t('nav.logs'), href: '/sorify/log-viewer', external: true, newTab: true, icon: ScrollText, accent: 'var(--md-sys-color-on-surface-variant)' },
] : []);

function isActive(href) {
    const url = page.url;
    if (href === '/sorify/') return url === '/sorify/' || url === '/sorify';
    return url.startsWith(href);
}

function logout() {
    router.post('/sorify/logout');
}

const userMenuOpen = ref(false);
const userMenuRef = ref(null);

function toggleUserMenu() {
    userMenuOpen.value = !userMenuOpen.value;
}

function closeUserMenu() {
    userMenuOpen.value = false;
}

function onClickOutside(event) {
    if (userMenuOpen.value && userMenuRef.value && !userMenuRef.value.contains(event.target)) {
        closeUserMenu();
    }
}

document.addEventListener('click', onClickOutside);
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
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
                                <component
                                    :is="link.icon"
                                    :size="16"
                                    class="flex-shrink-0"
                                    :style="!isActive(link.href) ? { color: link.accent } : null"
                                />
                                {{ link.label }}
                            </Link>

                            <a
                                :href="docsLink.href"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] flex items-center gap-1.5"
                            >
                                <component :is="docsLink.icon" :size="16" class="flex-shrink-0" :style="{ color: docsLink.accent }" />
                                {{ docsLink.label }}
                                <ExternalLink :size="14" class="flex-shrink-0 opacity-60" />
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
                                        class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20 flex items-center gap-1.5"
                                    >
                                        <component :is="link.icon" :size="16" class="flex-shrink-0" :style="{ color: link.accent }" />
                                        {{ link.label }}
                                    </a>
                                    <Link
                                        v-else
                                        :href="link.href"
                                        :class="[
                                            'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20 flex items-center gap-1.5',
                                            isActive(link.href) && 'bg-[var(--md-sys-color-tertiary)]/30'
                                        ]"
                                    >
                                        <component :is="link.icon" :size="16" class="flex-shrink-0" :style="{ color: link.accent }" />
                                        {{ link.label }}
                                    </Link>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Right side -->
                    <div class="flex items-center gap-3">
                        <!-- User nav -->
                        <div v-if="user" ref="userMenuRef" class="relative">
                            <button
                                type="button"
                                @click="toggleUserMenu"
                                class="flex items-center gap-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                            >
                                <Avatar :name="user.name" :email="user.email" :avatar-url="user.avatar_url" size="sm" />
                                {{ user.name }}
                                <ChevronDown
                                    :size="16"
                                    class="flex-shrink-0 transition-transform"
                                    :class="userMenuOpen && 'rotate-180'"
                                />
                            </button>

                            <div
                                v-if="userMenuOpen"
                                class="absolute right-0 top-full mt-2 w-48 rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container)] shadow-elevation-2 py-1 z-30"
                            >
                                <Link
                                    href="/sorify/profile"
                                    @click="closeUserMenu"
                                    class="flex items-center gap-2.5 px-4 py-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                                >
                                    <UserCircle :size="16" class="flex-shrink-0" :style="{ color: 'var(--md-sys-color-primary)' }" />
                                    {{ t('nav.profile') }}
                                </Link>
                                <button
                                    type="button"
                                    @click="logout"
                                    class="flex items-center gap-2.5 w-full px-4 py-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                                >
                                    <LogOut :size="16" class="flex-shrink-0" :style="{ color: 'var(--md-sys-color-error)' }" />
                                    {{ t('nav.logout') }}
                                </button>
                            </div>
                        </div>
                        <span v-else class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('nav.qaPlatform') }}</span>

                        <!-- Language switcher -->
                        <LanguageSwitcher />

                        <!-- Theme toggle -->
                        <IconButton
                            variant="standard"
                            :label="theme === 'dark' ? t('nav.switchToLight') : t('nav.switchToDark')"
                            @click="toggleTheme"
                        >
                            <Sun v-if="theme === 'dark'" :size="16" :style="{ color: 'var(--md-ext-color-warning)' }" />
                            <Moon v-else :size="16" :style="{ color: 'var(--md-sys-color-primary)' }" />
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
                        'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors flex items-center gap-1.5',
                        isActive(link.href)
                            ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)]'
                            : 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]'
                    ]"
                >
                    <component
                        :is="link.icon"
                        :size="16"
                        class="flex-shrink-0"
                        :style="!isActive(link.href) ? { color: link.accent } : null"
                    />
                    {{ link.label }}
                </Link>

                <a
                    :href="docsLink.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] flex items-center gap-1.5"
                >
                    <component :is="docsLink.icon" :size="16" class="flex-shrink-0" :style="{ color: docsLink.accent }" />
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
                            class="px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20 flex items-center gap-1.5"
                        >
                            <component :is="link.icon" :size="16" class="flex-shrink-0" :style="{ color: link.accent }" />
                            {{ link.label }}
                        </a>
                        <Link
                            v-else
                            :href="link.href"
                            :class="[
                                'px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large whitespace-nowrap transition-colors text-[var(--md-sys-color-on-tertiary-container)] hover:bg-[var(--md-sys-color-tertiary)]/20 flex items-center gap-1.5',
                                isActive(link.href) && 'bg-[var(--md-sys-color-tertiary)]/30'
                            ]"
                        >
                            <component :is="link.icon" :size="16" class="flex-shrink-0" :style="{ color: link.accent }" />
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
                {{ flash.success }}
            </Alert>
            <Alert v-if="flash.error" tone="error">
                {{ flash.error }}
            </Alert>
        </div>

        <!-- Page Content -->
        <main class="flex-1 min-h-0 max-w-screen-2xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 flex flex-col">
            <slot />
        </main>
    </div>
</template>
