<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Check, ChevronDown } from '@lucide/vue';

/**
 * Compact MD3 dropdown select for composer / toolbar rows.
 *
 * The trigger is a tonal pill (surface-container-high, corner-full)
 * with an optional leading icon and a chevron that rotates on open
 * (standard easing). The menu is an MD3 menu: surface-container over
 * the page with an elevation-2 shadow, corner-small, leading check
 * on the selected item, and a state layer on hover. It opens upward —
 * the component lives in bottom-anchored composer rows.
 */
const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    // [{ value, label, hint? }] — hint renders as a second line.
    options: { type: Array, required: true },
    // Leading icon component (e.g. Gauge from @lucide/vue).
    leadingIcon: { type: [Object, Function], default: null },
    title: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const root = ref(null);

const selected = computed(() => props.options.find((option) => option.value === props.modelValue));

function toggle() {
    if (! props.disabled) {
        open.value = ! open.value;
    }
}

function select(option) {
    if (option.value !== props.modelValue) {
        emit('update:modelValue', option.value);
        emit('change', option.value);
    }

    open.value = false;
}

function onDocumentClick(event) {
    if (open.value && root.value && ! root.value.contains(event.target)) {
        open.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="md-label-small inline-flex items-center gap-1 h-7 pl-2.5 pr-1.5 rounded-[var(--md-sys-shape-corner-full)] outline-none transition-colors duration-100 [transition-timing-function:var(--md-sys-motion-easing-standard)] focus-visible:ring-2 ring-[var(--md-sys-color-primary)] disabled:opacity-50 disabled:cursor-not-allowed"
            :class="open
                ? 'bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)]'
                : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] hover:bg-[var(--md-sys-color-surface-container-highest)]'"
            :title="title"
            :disabled="disabled"
            :aria-expanded="open"
            aria-haspopup="listbox"
            @click="toggle"
        >
            <component
                :is="leadingIcon"
                v-if="leadingIcon"
                :size="12"
                class="flex-shrink-0"
            />
            <span class="whitespace-nowrap">{{ selected?.label ?? modelValue }}</span>
            <ChevronDown
                :size="14"
                class="flex-shrink-0 transition-transform duration-150 [transition-timing-function:var(--md-sys-motion-easing-standard)]"
                :class="open ? 'rotate-180' : ''"
            />
        </button>

        <div
            v-if="open"
            class="absolute bottom-full left-0 mb-1.5 min-w-full z-20 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container)] shadow-elevation-2 overflow-hidden py-1"
            role="listbox"
        >
            <ul>
                <li v-for="option in options" :key="option.value">
                    <button
                        type="button"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        class="w-full flex items-center gap-2.5 text-left pl-2.5 pr-4 py-2 transition-colors hover:bg-[var(--md-sys-color-surface-container-high)]"
                        @click="select(option)"
                    >
                        <Check
                            :size="16"
                            class="flex-shrink-0"
                            :class="option.value === modelValue
                                ? 'text-[var(--md-sys-color-primary)]'
                                : 'text-transparent'"
                        />
                        <span class="min-w-0">
                            <span class="md-label-large text-[var(--md-sys-color-on-surface)] block whitespace-nowrap">{{ option.label }}</span>
                            <span v-if="option.hint" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] block whitespace-nowrap">{{ option.hint }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
