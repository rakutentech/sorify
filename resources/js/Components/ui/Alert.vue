<script setup>
defineProps({
    tone: { type: String, default: 'info' }, // success | error | warning | info
});
</script>

<template>
    <div
        class="flex items-center gap-3 rounded-[var(--md-sys-shape-corner-extra-small)] px-4 py-3 md-body-medium"
        :class="{
            'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]': tone === 'success',
            'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]': tone === 'error',
            'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]': tone === 'warning',
            'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]': tone === 'info',
        }"
    >
        <slot name="icon" />
        <div class="flex-1"><slot /></div>
    </div>
</template>
