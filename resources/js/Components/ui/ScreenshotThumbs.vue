<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    screenshots: { type: Array, default: () => [] },
    limit: { type: Number, default: 3 },
});

const emit = defineEmits(['open']);

const visible = computed(() => props.screenshots.slice(0, props.limit));
const extraCount = computed(() => Math.max(props.screenshots.length - props.limit, 0));

const hover = ref(null);

function showPreview(event, shot) {
    const rect = event.currentTarget.getBoundingClientRect();
    hover.value = {
        shot,
        top: rect.top - 8,
        left: rect.left + rect.width / 2,
    };
}

function hidePreview() {
    hover.value = null;
}
</script>

<template>
    <span v-if="screenshots.length" class="inline-flex items-center gap-1">
        <button
            v-for="(shot, i) in visible"
            :key="shot.id"
            type="button"
            class="w-8 h-8 rounded-[var(--md-sys-shape-corner-small)] overflow-hidden border border-[var(--md-sys-color-outline-variant)] flex-shrink-0 hover:border-[var(--md-sys-color-primary)] transition-colors"
            @click="emit('open', screenshots, i)"
            @mouseenter="showPreview($event, shot)"
            @mouseleave="hidePreview"
        >
            <img :src="shot.url" :alt="shot.label || shot.filename" class="w-full h-full object-cover" loading="lazy" />
        </button>
        <button
            v-if="extraCount > 0"
            type="button"
            class="w-8 h-8 rounded-[var(--md-sys-shape-corner-small)] flex items-center justify-center flex-shrink-0 border border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] md-label-small hover:border-[var(--md-sys-color-primary)] transition-colors"
            @click="emit('open', screenshots, limit)"
        >
            +{{ extraCount }}
        </button>
    </span>

    <Teleport to="body">
        <div
            v-if="hover"
            class="pointer-events-none fixed z-50 -translate-x-1/2 -translate-y-full flex flex-col items-center"
            :style="{ top: `${hover.top}px`, left: `${hover.left}px` }"
        >
            <div class="p-1 rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-inverse-surface)] shadow-elevation-2">
                <img :src="hover.shot.url" :alt="hover.shot.label || hover.shot.filename" class="w-[261px] h-[174px] object-contain rounded-[var(--md-sys-shape-corner-small)]" />
                <p v-if="hover.shot.label || hover.shot.filename" class="mt-1 px-1 text-center text-[var(--md-sys-color-inverse-on-surface)] md-label-small truncate max-w-[261px]">{{ hover.shot.label || hover.shot.filename }}</p>
            </div>
        </div>
    </Teleport>
</template>
