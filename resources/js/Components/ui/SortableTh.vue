<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowUp, ArrowDown, ChevronsUpDown } from '@lucide/vue';

const props = defineProps({
    field: { type: String, required: true },
    currentSort: { type: String, default: '' },
    currentDir: { type: String, default: 'desc' },
    align: { type: String, default: 'left' }, // 'left' | 'right' | 'center'
    title: { type: String, default: '' },
});

const emit = defineEmits(['sort']);

const { t } = useI18n();

const isActive = computed(() => props.currentSort === props.field);
const ariaSort = computed(() => {
    if (!isActive.value) return 'none';
    return props.currentDir === 'asc' ? 'ascending' : 'descending';
});

const iconSize = 13;

function onClick() {
    // Clicking an inactive column → sort desc by default.
    // Clicking the active column → flip direction.
    if (isActive.value) {
        emit('sort', props.field, props.currentDir === 'asc' ? 'desc' : 'asc');
    } else {
        emit('sort', props.field, 'desc');
    }
}

const alignClass = computed(() => ({
    left: 'text-left',
    right: 'text-right',
    center: 'text-center',
}[props.align] ?? 'text-left'));
</script>

<template>
    <th
        scope="col"
        :aria-sort="ariaSort"
        :class="[
            'px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider select-none',
            alignClass,
        ]"
    >
        <button
            type="button"
            @click="onClick"
            :title="title || (isActive
                ? (currentDir === 'asc' ? t('common.sortAscending') : t('common.sortDescending'))
                : t('common.sortAscending'))"
            class="inline-flex items-center gap-1 hover:text-[var(--md-sys-color-on-surface)] transition-colors cursor-pointer"
            :class="align === 'right' ? 'flex-row-reverse' : ''"
        >
            <slot />
            <ArrowUp v-if="isActive && currentDir === 'asc'" :size="iconSize" />
            <ArrowDown v-else-if="isActive && currentDir === 'desc'" :size="iconSize" />
            <ChevronsUpDown v-else :size="iconSize" class="opacity-50" />
        </button>
    </th>
</template>
