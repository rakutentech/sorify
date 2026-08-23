<script setup>
defineProps({
    text: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <span class="group relative inline-flex">
        <slot />
        <span
            v-if="text"
            class="pointer-events-none absolute left-1/2 bottom-full z-50 mb-1.5 -translate-x-1/2 translate-y-1 whitespace-nowrap rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-inverse-surface)] px-2.5 py-1.5 md-label-small text-[var(--md-sys-color-inverse-on-surface)] opacity-0 shadow-lg transition-all duration-150 group-hover:translate-y-0 group-hover:opacity-100"
        >
            {{ text }}
        </span>
    </span>
</template>
