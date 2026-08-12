<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
        required: true,
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
    skipped: 'neutral',
};

const TONE_CLASSES = {
    success: 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]',
    error: 'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]',
    warning: 'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]',
    info: 'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]',
    neutral: 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]',
};

const tone = computed(() => TONE_BY_STATUS[props.status] ?? 'neutral');
const pulse = computed(() => props.status === 'running');
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small"
        :class="TONE_CLASSES[tone]"
    >
        <span class="w-1.5 h-1.5 rounded-full bg-current" :class="pulse ? 'animate-pulse' : 'opacity-70'" />
        {{ status }}
    </span>
</template>
