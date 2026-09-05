<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ShieldCheck, ChevronDown, ExternalLink } from '@lucide/vue';

// One "Admin" dropdown button covering the admin-only navigation entries,
// used by the AppLayout (desktop + mobile navs).
defineProps({
    links: { type: Array, required: true },
});

const page = usePage();
const open = ref(false);
const root = ref(null);

function toggle() {
    open.value = ! open.value;
}

function close() {
    open.value = false;
}

function isActive(href) {
    return page.url.startsWith(href);
}

function onClickOutside(event) {
    if (open.value && root.value && ! root.value.contains(event.target)) {
        close();
    }
}

document.addEventListener('click', onClickOutside);
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            @click="toggle"
            :class="[
                'flex items-center gap-1.5 px-4 py-1.5 rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-colors text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary)]/15 border border-[var(--md-sys-color-tertiary)]/30 hover:bg-[var(--md-sys-color-tertiary)]/20 whitespace-nowrap',
                links.some(l => isActive(l.href)) && 'bg-[var(--md-sys-color-tertiary)]/30'
            ]"
        >
            <ShieldCheck :size="16" class="flex-shrink-0" :style="{ color: 'var(--md-sys-color-error)' }" />
            <span class="sr-only">{{ links.length }} admin items</span>
            Admin
            <ChevronDown :size="14" class="flex-shrink-0 transition-transform" :class="open && 'rotate-180'" />
        </button>

        <div
            v-if="open"
            class="absolute left-0 top-full mt-2 w-48 rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container)] shadow-elevation-2 py-1 z-30"
        >
            <template v-for="link in links" :key="link.href">
                <a
                    v-if="link.external"
                    :href="link.href"
                    :target="link.newTab ? '_blank' : undefined"
                    :rel="link.newTab ? 'noopener noreferrer' : undefined"
                    class="flex items-center gap-2.5 px-4 py-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                >
                    <component :is="link.icon" :size="16" class="flex-shrink-0" :style="{ color: link.accent }" />
                    {{ link.label }}
                    <ExternalLink :size="12" class="ml-auto opacity-50" />
                </a>
                <Link
                    v-else
                    :href="link.href"
                    @click="close"
                    :class="[
                        'flex items-center gap-2.5 px-4 py-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] hover:text-[var(--md-sys-color-on-surface)] transition-colors',
                        isActive(link.href) && 'text-[var(--md-sys-color-on-surface)]'
                    ]"
                >
                    <component :is="link.icon" :size="16" class="flex-shrink-0" :style="{ color: link.accent }" />
                    {{ link.label }}
                </Link>
            </template>
        </div>
    </div>
</template>
