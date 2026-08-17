<script setup>
import { useLocale } from '@/composables/useLocale.js';

const { locale, setLocale, locales } = useLocale();
</script>

<template>
    <select
        :value="locale"
        @change="setLocale($event.target.value)"
        class="h-10 px-3 rounded-[var(--md-sys-shape-corner-full)] bg-transparent text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] md-label-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)] cursor-pointer"
    >
        <option v-for="option in locales" :key="option.value" :value="option.value">
            {{ option.label }}
        </option>
    </select>
</template>
