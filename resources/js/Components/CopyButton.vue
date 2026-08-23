<script setup>
import { ref } from 'vue';
import { Clipboard, Check } from '@lucide/vue';

const props = defineProps({
    value: { type: String, required: true },
    label: { type: String, default: 'Copy' },
});

const copied = ref(false);

function copy() {
    navigator.clipboard.writeText(props.value).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    });
}
</script>

<template>
    <button
        type="button"
        @click="copy"
        class="flex-shrink-0 inline-flex items-center gap-1.5 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] px-3 py-1.5 rounded-[var(--md-sys-shape-corner-small)] transition-colors"
    >
        <Clipboard v-if="!copied" :size="16" />
        <Check v-else :size="16" />
        {{ copied ? 'Copied!' : label }}
    </button>
</template>
