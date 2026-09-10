<script setup>
defineProps({
    name: { type: String, required: true },
    id: { type: [Number, String], default: null }, // rendered as a subtle #id label after the name
    as: { type: String, default: 'span' },
    bold: { type: Boolean, default: true },
});
</script>

<template>
    <component :is="as" :class="{ 'font-semibold': bold }">
        <span
            v-if="id !== null && id !== ''"
            class="mr-1.5 inline-block font-normal tracking-wide text-[var(--md-sys-color-on-surface-variant)] opacity-60"
        >#{{ id }}</span>{{ name }}
    </component>
</template>
