import { ref, onMounted, onUnmounted } from 'vue';

export function useScreenshotLightbox() {
    const shots = ref([]);
    const index = ref(null);

    function open(newShots, i) {
        shots.value = newShots;
        index.value = i;
    }

    function close() {
        index.value = null;
    }

    function setIndex(i) {
        index.value = i;
    }

    function onKeydown(e) {
        if (index.value === null) return;
        if (e.key === 'ArrowLeft' && index.value > 0) index.value--;
        if (e.key === 'ArrowRight' && index.value < shots.value.length - 1) index.value++;
        if (e.key === 'Escape') close();
    }

    onMounted(() => window.addEventListener('keydown', onKeydown));
    onUnmounted(() => window.removeEventListener('keydown', onKeydown));

    return { shots, index, open, close, setIndex };
}
