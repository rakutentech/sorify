<script setup>
defineProps({
    variant: { type: String, default: 'filled' }, // filled | outlined | elevated | plain
    padding: { type: String, default: 'p-5' },
    square: { type: Boolean, default: false }, // drop the card's rounded corners
});
</script>

<template>
    <div
        :class="[
            square ? 'rounded-none' : 'rounded-[var(--md-sys-shape-corner-medium)]',
            padding,
            {
                'bg-[var(--md-sys-color-surface-container-low)]': variant === 'filled',
                'bg-[var(--md-sys-color-surface)] border border-[var(--md-sys-color-outline-variant)]': variant === 'outlined',
                'bg-[var(--md-sys-color-surface-container-low)] shadow-elevation-1': variant === 'elevated',
                'bg-transparent': variant === 'plain',
            },
        ]"
    >
        <slot />
    </div>
</template>
