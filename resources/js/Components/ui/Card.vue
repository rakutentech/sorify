<script setup>
defineProps({
    variant: { type: String, default: 'filled' }, // filled | outlined | elevated
    padding: { type: String, default: 'p-5' },
});
</script>

<template>
    <div
        class="rounded-[var(--md-sys-shape-corner-medium)]"
        :class="[
            padding,
            {
                'bg-[var(--md-sys-color-surface-container-low)]': variant === 'filled',
                'bg-[var(--md-sys-color-surface)] border border-[var(--md-sys-color-outline-variant)]': variant === 'outlined',
                'bg-[var(--md-sys-color-surface-container-low)] shadow-elevation-1': variant === 'elevated',
            },
        ]"
    >
        <slot />
    </div>
</template>
