<script setup>
defineProps({
    code: {
        type: String,
        default: '',
    },
    editable: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['update:code']);
</script>

<template>
    <div class="rounded-[var(--md-sys-shape-corner-small)] overflow-hidden border border-[var(--md-sys-color-outline-variant)]">
        <!-- Header bar -->
        <div class="flex items-center justify-between bg-[var(--md-sys-color-surface-container-high)] px-4 py-2 border-b border-[var(--md-sys-color-outline-variant)]">
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-[var(--md-sys-color-surface-container-highest)]"></span>
                <span class="w-3 h-3 rounded-full bg-[var(--md-sys-color-surface-container-highest)]"></span>
                <span class="w-3 h-3 rounded-full bg-[var(--md-sys-color-surface-container-highest)]"></span>
            </div>
            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] font-mono">playwright test</span>
        </div>

        <!-- Editable textarea -->
        <textarea
            v-if="editable"
            :value="code"
            @input="$emit('update:code', $event.target.value)"
            class="w-full bg-code text-[var(--md-sys-color-on-surface-variant)] font-mono md-body-medium p-4 resize-none focus:outline-none min-h-[24rem] leading-relaxed"
            spellcheck="false"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
        />

        <!-- Read-only pre block -->
        <pre
            v-else
            class="bg-code text-[var(--md-sys-color-on-surface-variant)] font-mono md-body-medium p-4 overflow-x-auto min-h-[6rem] leading-relaxed whitespace-pre"
        ><code>{{ code || '// No code generated yet.' }}</code></pre>
    </div>
</template>
