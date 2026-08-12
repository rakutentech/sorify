<script setup>
import { ref } from 'vue';

const props = defineProps({
    value: { type: String, required: true },
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
    <div class="flex gap-2">
        <input
            :value="value"
            type="text"
            readonly
            class="flex-1 min-w-0 bg-[var(--md-sys-color-surface-container-high)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-2 text-[var(--md-sys-color-on-surface-variant)] md-body-small font-mono truncate focus:outline-none"
            @focus="$event.target.select()"
        />
        <button
            @click="copy"
            class="flex-shrink-0 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] px-3 py-2 rounded-[var(--md-sys-shape-corner-small)] transition-colors"
        >
            {{ copied ? 'Copied!' : 'Copy' }}
        </button>
    </div>
</template>
