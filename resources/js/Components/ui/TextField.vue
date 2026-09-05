<script setup>
import { useId, computed, useSlots } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
    placeholder: { type: String, default: null },
    autocomplete: { type: String, default: null },
    required: { type: Boolean, default: false },
    rows: { type: Number, default: null },
    mono: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const id = useId();
const slots = useSlots();
const hasLeading = computed(() => !!slots.leading);
</script>

<template>
    <div>
        <label :for="id" class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">
            {{ label }}
        </label>
        <div class="relative">
            <span
                v-if="hasLeading"
                class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-[var(--md-sys-color-on-surface-variant)]"
            >
                <slot name="leading" />
            </span>
            <textarea
                v-if="type === 'textarea'"
                :id="id"
                :rows="rows ?? 4"
                :value="modelValue"
                :required="required"
                :placeholder="placeholder"
                @input="$emit('update:modelValue', $event.target.value)"
                class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border text-[var(--md-sys-color-on-surface)] md-body-medium placeholder:text-[var(--md-sys-color-on-surface-variant)] placeholder:opacity-60 focus:outline-none focus:ring-2 transition-colors"
                :class="[
                    hasLeading ? 'pl-10' : '',
                    mono ? '!font-mono !text-sm' : '',
                    error
                        ? 'border-[var(--md-sys-color-error)] focus:ring-[var(--md-sys-color-error)]'
                        : 'border-[var(--md-sys-color-outline)] focus:ring-[var(--md-sys-color-primary)] focus:border-transparent',
                ]"
            />
            <input
                v-else
                :id="id"
                :type="type"
                :value="modelValue"
                :autocomplete="autocomplete"
                :required="required"
                :placeholder="placeholder"
                @input="$emit('update:modelValue', $event.target.value)"
                class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border text-[var(--md-sys-color-on-surface)] md-body-medium placeholder:text-[var(--md-sys-color-on-surface-variant)] placeholder:opacity-60 focus:outline-none focus:ring-2 transition-colors"
                :class="[
                    hasLeading ? 'pl-10' : '',
                    error
                        ? 'border-[var(--md-sys-color-error)] focus:ring-[var(--md-sys-color-error)]'
                        : 'border-[var(--md-sys-color-outline)] focus:ring-[var(--md-sys-color-primary)] focus:border-transparent',
                ]"
            />
        </div>
        <p v-if="error" class="mt-1.5 md-body-small text-[var(--md-sys-color-error)]">{{ error }}</p>
        <p v-else-if="hint" class="mt-1.5 md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ hint }}</p>
    </div>
</template>
