<script setup>
import { ref, computed } from 'vue';
import { Modal } from '@/Components/ui';

const props = defineProps({
    screenshots: {
        type: Array,
        default: () => [],
    },
});

const activeIndex = ref(null);

const activeScreenshot = computed(() =>
    activeIndex.value !== null ? props.screenshots[activeIndex.value] : null,
);

function open(index) {
    activeIndex.value = index;
}

function close() {
    activeIndex.value = null;
}

function prev() {
    if (activeIndex.value > 0) activeIndex.value--;
}

function next() {
    if (activeIndex.value < props.screenshots.length - 1) activeIndex.value++;
}

function onKeydown(e) {
    if (activeIndex.value === null) return;
    if (e.key === 'ArrowLeft') prev();
    if (e.key === 'ArrowRight') next();
    if (e.key === 'Escape') close();
}

function formatMs(ms) {
    if (!ms) return '';
    return new Date(ms).toLocaleString();
}
</script>

<template>
    <div @keydown="onKeydown" tabindex="-1">
        <!-- Empty state -->
        <p v-if="!screenshots.length" class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] italic">No screenshots.</p>

        <!-- Thumbnail grid -->
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
            <button
                v-for="(shot, i) in screenshots"
                :key="shot.id"
                class="group relative aspect-video bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] overflow-hidden border border-[var(--md-sys-color-outline-variant)] hover:border-[var(--md-sys-color-primary)] transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)]"
                @click="open(i)"
            >
                <img
                    :src="shot.url"
                    :alt="shot.label || shot.filename"
                    class="w-full h-full object-cover"
                    loading="lazy"
                />
                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <p class="text-white md-label-small truncate">{{ shot.label || shot.filename }}</p>
                </div>
            </button>
        </div>

        <!-- Lightbox overlay -->
        <Modal :show="!!activeScreenshot" bare overlay-class="bg-[var(--md-sys-color-scrim)]/90" @close="close">
                <!-- Close button -->
                <button
                    class="absolute top-4 right-4 text-white/70 hover:text-white bg-white/10 rounded-[var(--md-sys-shape-corner-full)] p-2 transition-colors"
                    @click="close"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>

                <!-- Prev button -->
                <button
                    v-if="activeIndex > 0"
                    class="absolute left-4 text-white/70 hover:text-white bg-white/10 rounded-[var(--md-sys-shape-corner-full)] p-3 transition-colors"
                    @click="prev"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>

                <!-- Image -->
                <div class="max-w-5xl max-h-screen p-4 flex flex-col items-center gap-3">
                    <img
                        :src="activeScreenshot.url"
                        :alt="activeScreenshot.label || activeScreenshot.filename"
                        class="max-w-full max-h-[80vh] object-contain rounded-[var(--md-sys-shape-corner-medium)] shadow-elevation-3"
                    />
                    <div class="text-center">
                        <p class="text-white md-body-medium font-medium">{{ activeScreenshot.label || activeScreenshot.filename }}</p>
                        <p v-if="activeScreenshot.taken_at_ms" class="text-white/60 md-label-small mt-0.5">
                            {{ formatMs(activeScreenshot.taken_at_ms) }}
                        </p>
                        <p class="text-white/50 md-label-small mt-0.5">
                            {{ activeIndex + 1 }} / {{ screenshots.length }}
                        </p>
                    </div>
                </div>

                <!-- Next button -->
                <button
                    v-if="activeIndex < screenshots.length - 1"
                    class="absolute right-4 text-white/70 hover:text-white bg-white/10 rounded-[var(--md-sys-shape-corner-full)] p-3 transition-colors"
                    @click="next"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
        </Modal>
    </div>
</template>
