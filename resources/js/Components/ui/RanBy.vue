<script setup>
import { computed } from 'vue';
import Avatar from './Avatar.vue';

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

const sourceLabel = computed(() => SOURCE_LABELS[props.triggeredBy] ?? 'Manual');
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
        class="group relative w-7 h-7 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center flex-shrink-0"
    >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
        </svg>
        <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
            <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                {{ sourceLabel }}
            </div>
        </div>
    </div>
</template>
