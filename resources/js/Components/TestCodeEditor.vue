<script setup>
import { computed, ref } from 'vue';
import Prism from 'prismjs';
import 'prismjs/components/prism-clike';
import 'prismjs/components/prism-javascript';

const props = defineProps({
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

const COLLAPSE_LINE_THRESHOLD = 14;

const expanded = ref(false);

const lineCount = computed(() => (props.code ? props.code.split('\n').length : 0));
const isCollapsible = computed(() => !props.editable && lineCount.value > COLLAPSE_LINE_THRESHOLD);

const highlightedCode = computed(() => {
    if (!props.code) return '// No code generated yet.';
    return Prism.highlight(props.code, Prism.languages.javascript, 'javascript');
});
</script>

<template>
    <div class="playwright-code-block rounded-[var(--md-sys-shape-corner-small)] overflow-hidden border border-[var(--md-sys-color-outline-variant)]">
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

        <!-- Read-only, syntax-highlighted, collapsible pre block -->
        <template v-else>
            <div class="relative">
                <pre
                    class="bg-code text-[var(--md-sys-color-on-surface-variant)] font-mono md-body-medium p-4 overflow-x-auto leading-relaxed whitespace-pre"
                    :class="isCollapsible && !expanded ? 'max-h-80 overflow-y-hidden' : 'min-h-[6rem]'"
                ><code v-html="highlightedCode"></code></pre>

                <div
                    v-if="isCollapsible && !expanded"
                    class="absolute bottom-0 inset-x-0 h-16 bg-gradient-to-t from-[var(--code-bg)] to-transparent pointer-events-none"
                ></div>
            </div>

            <button
                v-if="isCollapsible"
                type="button"
                @click="expanded = !expanded"
                class="w-full flex items-center justify-center gap-1.5 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] border-t border-[var(--md-sys-color-outline-variant)] py-2 transition-colors"
            >
                <svg
                    class="w-3.5 h-3.5 transition-transform"
                    :class="{ 'rotate-180': expanded }"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
                {{ expanded ? 'Show less' : `Show all ${lineCount} lines` }}
            </button>
        </template>
    </div>
</template>
