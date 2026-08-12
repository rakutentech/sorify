<script setup>
defineProps({
    show: { type: Boolean, required: true },
    title: { type: String, default: null },
    maxWidth: { type: String, default: 'max-w-lg' },
    bare: { type: Boolean, default: false },
    overlayClass: { type: String, default: 'bg-[var(--md-sys-color-scrim)]/60' },
});

defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center p-4"
            :class="overlayClass"
            tabindex="-1"
            @click.self="$emit('close')"
            @keydown.escape="$emit('close')"
        >
            <template v-if="bare">
                <slot />
            </template>
            <div
                v-else
                class="bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-extra-large)] w-full shadow-elevation-3 max-h-[90vh] flex flex-col"
                :class="maxWidth"
            >
                <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex-shrink-0">
                    <slot name="header">
                        <h2 class="md-title-large text-[var(--md-sys-color-on-surface)]">{{ title }}</h2>
                    </slot>
                    <button @click="$emit('close')" class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto min-h-0">
                    <slot />
                </div>
                <div v-if="$slots.footer" class="flex justify-end gap-3 px-6 py-4 border-t border-[var(--md-sys-color-outline-variant)] flex-shrink-0">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
