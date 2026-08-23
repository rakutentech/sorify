<script setup>
import { computed } from 'vue';
import Avatar from './Avatar.vue';
import { SOURCE_ICON } from '@/utils/iconMaps.js';

const props = defineProps({
    triggeredBy: { type: String, default: null },
    triggeredByUser: { type: Object, default: null },
});

const SOURCE_LABELS = {
    ci: 'CI (GitHub Actions)',
    schedule: 'Scheduled',
    mcp: 'MCP',
    manual: 'Manual',
};

// Accent color per source for visual distinction.
const SOURCE_COLOR = {
    ci: 'var(--md-sys-color-primary)',
    schedule: 'var(--md-ext-color-success)',
    mcp: 'var(--md-sys-color-tertiary)',
    manual: 'var(--md-ext-color-warning)',
};

const sourceLabel = computed(() => SOURCE_LABELS[props.triggeredBy] ?? 'Manual');
const sourceIcon = computed(() => SOURCE_ICON[props.triggeredBy] ?? SOURCE_ICON.manual);
const sourceColor = computed(() => SOURCE_COLOR[props.triggeredBy] ?? 'var(--md-ext-color-warning)');
</script>

<template>
    <Avatar
        v-if="triggeredByUser"
        :name="triggeredByUser.name"
        :email="triggeredByUser.email"
        :avatar-url="triggeredByUser.avatar_url"
    />
    <div
        v-else
        class="group relative w-7 h-7 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-surface-container-high)] flex items-center justify-center flex-shrink-0"
        :style="{ color: sourceColor }"
    >
        <component :is="sourceIcon" :size="14" class="flex-shrink-0" />
        <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
            <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                {{ sourceLabel }}
            </div>
        </div>
    </div>
</template>
