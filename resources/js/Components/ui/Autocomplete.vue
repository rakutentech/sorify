<script setup>
import { useId, ref, computed, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    options: { type: Array, default: () => [] },
    label: { type: String, required: true },
    placeholder: { type: String, default: 'Search by name or email…' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const id = useId();
const query = ref(props.modelValue);
const open = ref(false);
const activeIndex = ref(-1);

watch(() => props.modelValue, (value) => {
    if (value !== query.value) query.value = value;
});

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    const matches = q
        ? props.options.filter((o) => o.name.toLowerCase().includes(q) || o.email.toLowerCase().includes(q))
        : props.options;
    return matches.slice(0, 8);
});

function onInput(event) {
    query.value = event.target.value;
    activeIndex.value = -1;
    open.value = true;
    emit('update:modelValue', query.value);
}

function select(option) {
    query.value = option.email;
    open.value = false;
    emit('update:modelValue', option.email);
}

function onKeydown(event) {
    if (!open.value) return;
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter') {
        if (activeIndex.value >= 0 && filtered.value[activeIndex.value]) {
            event.preventDefault();
            select(filtered.value[activeIndex.value]);
        }
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onFocusout(event) {
    if (event.currentTarget.contains(event.relatedTarget)) return;
    open.value = false;
}
</script>

<template>
    <div class="relative" @focusout="onFocusout">
        <label :for="id" class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">
            {{ label }}
        </label>
        <input
            :id="id"
            type="text"
            :value="query"
            :placeholder="placeholder"
            autocomplete="off"
            @input="onInput"
            @focus="open = true"
            @keydown="onKeydown"
            class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border text-[var(--md-sys-color-on-surface)] md-body-medium placeholder:text-[var(--md-sys-color-on-surface-variant)] focus:outline-none focus:ring-2 transition-colors"
            :class="error
                ? 'border-[var(--md-sys-color-error)] focus:ring-[var(--md-sys-color-error)]'
                : 'border-[var(--md-sys-color-outline)] focus:ring-[var(--md-sys-color-primary)] focus:border-transparent'"
        />
        <div
            v-if="open && filtered.length"
            class="absolute z-10 mt-1 w-full bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] shadow-elevation-2 border border-[var(--md-sys-color-outline-variant)] max-h-56 overflow-y-auto"
        >
            <button
                v-for="(option, index) in filtered"
                :key="option.id"
                type="button"
                @click="select(option)"
                @mouseenter="activeIndex = index"
                class="w-full text-left px-3.5 py-2 transition-colors"
                :class="index === activeIndex ? 'bg-[var(--md-sys-color-surface-container-highest)]' : 'hover:bg-[var(--md-sys-color-surface-container-highest)]'"
            >
                <p class="md-body-medium text-[var(--md-sys-color-on-surface)]">{{ option.name }}</p>
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ option.email }}</p>
            </button>
        </div>
        <p v-if="error" class="mt-1.5 md-body-small text-[var(--md-sys-color-error)]">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ hint }}</p>
    </div>
</template>
