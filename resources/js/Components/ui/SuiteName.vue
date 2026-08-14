<script setup>
defineProps({
    name: { type: String, required: true },
    as: { type: String, default: 'span' },
    bold: { type: Boolean, default: true },
});
</script>

<template>
    <component :is="as" :class="{ 'font-semibold': bold }">{{ name }}</component>
</template>
