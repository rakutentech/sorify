<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Languages, Check } from '@lucide/vue';
import { useLocale } from '@/composables/useLocale.js';

const { locale, setLocale, locales } = useLocale();

const open = ref(false);
const root = ref(null);

function toggle() {
    open.value = !open.value;
}

function choose(value) {
    setLocale(value);
    open.value = false;
}

function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));

// Compact two-letter code for the button chip.
function shortLabel(value) {
    return value.split('-')[0].toUpperCase();
}
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            aria-label="Language"
            class="h-10 pl-2.5 pr-3 flex items-center gap-1.5 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] md-label-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)] cursor-pointer"
            @click="toggle"
        >
            <Languages :size="18" />
            <span class="md-label-small">{{ shortLabel(locale) }}</span>
        </button>

        <Transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <ul
                v-if="open"
                class="absolute right-0 mt-1.5 min-w-[160px] py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container)] border border-[var(--md-sys-color-outline-variant)] shadow-elevation-2 z-50"
            >
                <li v-for="option in locales" :key="option.value">
                    <button
                        type="button"
                        class="w-full flex items-center justify-between gap-3 px-3 py-2 md-label-medium text-left transition-colors"
                        :class="locale === option.value
                            ? 'text-[var(--md-sys-color-primary)]'
                            : 'text-[var(--md-sys-color-on-surface)] hover:bg-[var(--md-sys-color-surface-container-high)]'"
                        @click="choose(option.value)"
                    >
                        <span>{{ option.label }}</span>
                        <Check v-if="locale === option.value" :size="16" />
                    </button>
                </li>
            </ul>
        </Transition>
    </div>
</template>
