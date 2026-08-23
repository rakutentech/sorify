<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import Prism from 'prismjs';
import 'prismjs/components/prism-clike';
import 'prismjs/components/prism-javascript';
import { Table, ChevronDown } from '@lucide/vue';

const props = defineProps({
    code: {
        type: String,
        default: '',
    },
    editable: {
        type: Boolean,
        default: false,
    },
    variables: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['update:code']);

const COLLAPSE_LINE_THRESHOLD = 14;

const expanded = ref(false);

const lineCount = computed(() => (props.code ? props.code.split('\n').length : 0));
const isCollapsible = computed(() => !props.editable && lineCount.value > COLLAPSE_LINE_THRESHOLD);

const highlightedCode = computed(() => {
    if (!props.code) return '// No code generated yet.';
    return Prism.highlight(props.code, Prism.languages.javascript, 'javascript');
});

// ── Insert variable picker ──────────────────────────────────────────────────
const textareaRef = ref(null);
const showVariablePicker = ref(false);
const pickerQuery = ref('');
const pickerActiveIndex = ref(0);
const pickerRef = ref(null);

const variableOptions = computed(() => (props.variables ?? []).filter(v => v.key));

const filteredVariables = computed(() => {
    const q = pickerQuery.value.trim().toLowerCase();
    if (!q) return variableOptions.value;
    return variableOptions.value.filter(v => v.key.toLowerCase().includes(q));
});

function openVariablePicker() {
    showVariablePicker.value = true;
    pickerQuery.value = '';
    pickerActiveIndex.value = 0;
    nextTick(() => pickerRef.value?.querySelector('input')?.focus());
}

function closeVariablePicker() {
    showVariablePicker.value = false;
    pickerQuery.value = '';
}

function insertVariable(variable) {
    const textarea = textareaRef.value;
    const insert = `variables.${variable.key}`;

    if (!textarea) {
        emit('update:code', (props.code ?? '') + insert);
        closeVariablePicker();
        return;
    }

    const start = textarea.selectionStart ?? (props.code ?? '').length;
    const end = textarea.selectionEnd ?? start;
    const next = (props.code ?? '').slice(0, start) + insert + (props.code ?? '').slice(end);

    emit('update:code', next);
    closeVariablePicker();

    nextTick(() => {
        textarea.focus();
        const pos = start + insert.length;
        textarea.setSelectionRange(pos, pos);
    });
}

function onPickerKeydown(event) {
    if (!showVariablePicker.value) return;
    const list = filteredVariables.value;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        pickerActiveIndex.value = Math.min(pickerActiveIndex.value + 1, list.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        pickerActiveIndex.value = Math.max(pickerActiveIndex.value - 1, 0);
    } else if (event.key === 'Enter') {
        if (list[pickerActiveIndex.value]) {
            event.preventDefault();
            insertVariable(list[pickerActiveIndex.value]);
        }
    } else if (event.key === 'Escape') {
        event.preventDefault();
        closeVariablePicker();
    }
}

function onPickerInput() {
    pickerActiveIndex.value = 0;
}

function onClickOutsidePicker(event) {
    if (showVariablePicker.value && pickerRef.value && !pickerRef.value.contains(event.target)) {
        closeVariablePicker();
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutsidePicker));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutsidePicker));
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
            <div class="flex items-center gap-2">
                <!-- Insert variable picker (editable + variables available) -->
                <div v-if="editable && variableOptions.length" ref="pickerRef" class="relative">
                    <button
                        type="button"
                        @click="showVariablePicker ? closeVariablePicker() : openVariablePicker()"
                        class="flex items-center gap-1 md-label-small text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-primary)] transition-colors px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] hover:bg-[var(--md-sys-color-surface-container-highest)]"
                        :title="showVariablePicker ? '' : 'Insert variable'"
                    >
                        <Table :size="16" />
                        <span>{ } Insert</span>
                    </button>
                    <div
                        v-if="showVariablePicker"
                        class="absolute right-0 top-full mt-1 z-20 w-64 bg-[var(--md-sys-color-surface-container-high)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] shadow-elevation-2 overflow-hidden"
                        @keydown="onPickerKeydown"
                    >
                        <div class="p-2 border-b border-[var(--md-sys-color-outline-variant)]">
                            <input
                                v-model="pickerQuery"
                                @input="onPickerInput"
                                type="text"
                                placeholder="Search variables…"
                                autocomplete="off"
                                class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            />
                        </div>
                        <div class="max-h-56 overflow-y-auto">
                            <button
                                v-for="(variable, index) in filteredVariables"
                                :key="variable.key"
                                type="button"
                                @mousedown.prevent="insertVariable(variable)"
                                @mouseenter="pickerActiveIndex = index"
                                class="w-full text-left px-3 py-1.5 transition-colors"
                                :class="index === pickerActiveIndex ? 'bg-[var(--md-sys-color-surface-container-highest)]' : 'hover:bg-[var(--md-sys-color-surface-container-highest)]'"
                            >
                                <p class="md-label-small font-mono font-semibold text-[var(--md-sys-color-primary)]">variables.{{ variable.key }}</p>
                                <p v-if="variable.value" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] truncate">{{ variable.value }}</p>
                            </button>
                            <p v-if="!filteredVariables.length" class="px-3 py-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] italic">No matching variables.</p>
                        </div>
                    </div>
                </div>
                <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] font-mono">playwright test</span>
            </div>
        </div>

        <!-- Editable textarea -->
        <textarea
            v-if="editable"
            ref="textareaRef"
            :value="code"
            @input="$emit('update:code', $event.target.value)"
            class="w-full bg-code text-[var(--md-sys-color-on-surface-variant)] font-mono md-body-small p-4 resize-none focus:outline-none min-h-[24rem] leading-relaxed"
            spellcheck="false"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
        />

        <!-- Read-only, syntax-highlighted, collapsible pre block -->
        <template v-else>
            <div class="relative">
                <pre
                    class="bg-code text-[var(--md-sys-color-on-surface-variant)] font-mono md-body-small p-4 overflow-x-auto leading-relaxed whitespace-pre"
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
                <ChevronDown
                    :size="16"
                    class="transition-transform"
                    :class="{ 'rotate-180': expanded }"
                />
                {{ expanded ? 'Show less' : `Show all ${lineCount} lines` }}
            </button>
        </template>
    </div>
</template>
