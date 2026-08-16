<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    email: { type: String, default: '' },
    size: { type: String, default: 'md' },
});

const initial = computed(() => (props.name?.trim()?.[0] ?? '?').toUpperCase());

const sizeClass = computed(() => (props.size === 'sm' ? 'w-5 h-5' : 'w-7 h-7'));

const AVATAR_COLORS = [
    '#EF4444', '#F97316', '#F59E0B', '#EAB308', '#84CC16', '#22C55E',
    '#10B981', '#14B8A6', '#06B6D4', '#0EA5E9', '#3B82F6', '#6366F1',
    '#8B5CF6', '#A855F7', '#D946EF', '#EC4899', '#F43F5E', '#78716C',
];

const avatarColor = computed(() => {
    const code = initial.value.charCodeAt(0) || 0;
    return AVATAR_COLORS[code % AVATAR_COLORS.length];
});
</script>

<template>
    <div class="group relative">
        <div
            :class="[sizeClass, 'rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] text-white flex items-center justify-center md-label-small font-medium select-none']"
            :style="{ backgroundColor: avatarColor }"
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
