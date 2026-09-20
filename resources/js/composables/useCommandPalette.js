import { ref } from 'vue';

/**
 * Global command palette (Cmd/Ctrl+K) control.
 *
 * The palette itself is mounted once in AppLayout, but the same
 * module-level ref pattern as useAgentDrawer lets any page open it
 * programmatically later.
 */
const open = ref(false);

export function openCommandPalette() {
    open.value = true;
}

export function closeCommandPalette() {
    open.value = false;
}

export function toggleCommandPalette() {
    open.value = !open.value;
}

export function useCommandPalette() {
    return { open, openCommandPalette, closeCommandPalette, toggleCommandPalette };
}
