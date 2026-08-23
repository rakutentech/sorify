<script setup>
import { computed } from 'vue';
import { STATUS_ICON } from '@/utils/iconMaps.js';

const props = defineProps({
    status: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        default: null,
    },
    fixed: {
        type: Boolean,
        default: false,
    },
});

const TONE_BY_STATUS = {
    passed: 'success',
    completed: 'success',
    active: 'success',
    failed: 'error',
    error: 'warning',
    timeout: 'warning',
    draft: 'warning',
    running: 'info',
    pending: 'info',
    cancelled: 'neutral',
    disabled: 'neutral',
    never_ran: 'neutral',
};

const TONE_CLASSES = {
    success: 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]',
    error: 'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]',
    warning: 'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]',
    info: 'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]',
    neutral: 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]',
};

const tone = computed(() => TONE_BY_STATUS[props.status] ?? 'neutral');
const statusIcon = computed(() => STATUS_ICON[props.status] ?? null);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small capitalize flex-shrink-0"
        :class="[TONE_CLASSES[tone], fixed ? 'min-w-[6.5rem] justify-center' : '']"
    >
        <component
            :is="statusIcon.icon"
            v-if="statusIcon"
            :size="13"
            class="flex-shrink-0"
            :class="statusIcon.spin && 'animate-spin'"
        />
        {{ label ?? status }}
    </span>
</template>
