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
    <span v-if="triggeredByUser" class="inline-flex items-center gap-2">
        <Avatar :name="triggeredByUser.name" :email="triggeredByUser.email" />
        <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ triggeredByUser.name }}</span>
    </span>
    <span
        v-else
        class="inline-flex items-center md-label-small px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]"
    >
        {{ sourceLabel }}
    </span>
</template>
