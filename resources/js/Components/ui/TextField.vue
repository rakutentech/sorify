<script setup>
import { useId } from 'vue';

defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    autocomplete: { type: String, default: null },
    required: { type: Boolean, default: false },
    rows: { type: Number, default: null },
});

defineEmits(['update:modelValue']);

const id = useId();
</script>

<template>
    <div>
        <label :for="id" class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">
            {{ label }}
        </label>
        <textarea
            v-if="type === 'textarea'"
            :id="id"
            :rows="rows ?? 4"
            :value="modelValue"
            :required="required"
            @input="$emit('update:modelValue', $event.target.value)"
            class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border text-[var(--md-sys-color-on-surface)] md-body-medium placeholder:text-[var(--md-sys-color-on-surface-variant)] focus:outline-none focus:ring-2 transition-colors"
            :class="error
                ? 'border-[var(--md-sys-color-error)] focus:ring-[var(--md-sys-color-error)]'
                : 'border-[var(--md-sys-color-outline)] focus:ring-[var(--md-sys-color-primary)] focus:border-transparent'"
        />
        <input
            v-else
            :id="id"
            :type="type"
            :value="modelValue"
            :autocomplete="autocomplete"
            :required="required"
            @input="$emit('update:modelValue', $event.target.value)"
            class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border text-[var(--md-sys-color-on-surface)] md-body-medium placeholder:text-[var(--md-sys-color-on-surface-variant)] focus:outline-none focus:ring-2 transition-colors"
            :class="error
                ? 'border-[var(--md-sys-color-error)] focus:ring-[var(--md-sys-color-error)]'
                : 'border-[var(--md-sys-color-outline)] focus:ring-[var(--md-sys-color-primary)] focus:border-transparent'"
        />
        <p v-if="error" class="mt-1.5 md-body-small text-[var(--md-sys-color-error)]">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ hint }}</p>
    </div>
</template>
