<script setup>
defineProps({
    name: { type: String, required: true },
    id: { type: [Number, String], default: null }, // rendered as a subtle #id label after the name
});
</script>

<template>
    <span
        v-if="id !== null && id !== ''"
        class="mr-1.5 inline-block font-normal tracking-wide text-[var(--md-sys-color-on-surface-variant)] opacity-60"
    >#{{ id }}</span>{{ name }}
</template>
