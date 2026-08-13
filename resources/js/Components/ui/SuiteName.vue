<script setup>
defineProps({
    name: { type: String, required: true },
    as: { type: String, default: 'span' },
});
</script>

<template>
    <component :is="as" class="font-semibold">{{ name }}</component>
</template>
