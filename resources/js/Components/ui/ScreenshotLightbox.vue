<script setup>
import { computed } from 'vue';
import Modal from './Modal.vue';
import { X, ChevronLeft, ChevronRight } from '@lucide/vue';

const props = defineProps({
    shots: { type: Array, default: () => [] },
    index: { type: Number, default: null },
});

const emit = defineEmits(['close', 'update:index']);

const activeShot = computed(() => (props.index !== null ? props.shots[props.index] : null));

function prev() {
    if (props.index > 0) emit('update:index', props.index - 1);
}

function next() {
    if (props.index < props.shots.length - 1) emit('update:index', props.index + 1);
}
</script>

<template>
    <Modal :show="!!activeShot" bare overlay-class="bg-[var(--md-sys-color-scrim)]/90" @close="emit('close')">
        <button
            class="absolute top-4 right-4 text-white/70 hover:text-white bg-white/10 rounded-[var(--md-sys-shape-corner-full)] p-2 transition-colors"
            @click="emit('close')"
        >
            <X :size="20" />
        </button>

        <button
            v-if="index > 0"
            class="absolute left-4 text-white/70 hover:text-white bg-white/10 rounded-[var(--md-sys-shape-corner-full)] p-3 transition-colors"
            @click="prev"
        >
            <ChevronLeft :size="20" />
        </button>

        <div v-if="activeShot" class="max-w-5xl max-h-screen p-4 flex flex-col items-center gap-3">
            <img
                :src="activeShot.url"
                :alt="activeShot.label || activeShot.filename"
                class="max-w-full max-h-[80vh] object-contain rounded-[var(--md-sys-shape-corner-medium)] shadow-elevation-3"
            />
            <div class="text-center">
                <p v-if="activeShot.label || activeShot.filename" class="text-white md-body-medium font-medium">{{ activeShot.label || activeShot.filename }}</p>
                <p class="text-white/50 md-label-small mt-0.5">{{ index + 1 }} / {{ shots.length }}</p>
            </div>
        </div>

        <button
            v-if="index < shots.length - 1"
            class="absolute right-4 text-white/70 hover:text-white bg-white/10 rounded-[var(--md-sys-shape-corner-full)] p-3 transition-colors"
            @click="next"
        >
            <ChevronRight :size="20" />
        </button>
    </Modal>
</template>
