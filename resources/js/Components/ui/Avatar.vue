<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    email: { type: String, default: '' },
});

const initial = computed(() => (props.name?.trim()?.[0] ?? '?').toUpperCase());
</script>

<template>
    <div class="group relative">
        <div
            class="w-7 h-7 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] flex items-center justify-center md-label-small font-medium select-none"
        >
            {{ initial }}
        </div>
        <div
            class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap"
        >
            <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                <p class="font-medium">{{ name }}</p>
                <p v-if="email" class="opacity-80">{{ email }}</p>
            </div>
        </div>
    </div>
</template>
