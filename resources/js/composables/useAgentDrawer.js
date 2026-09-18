import { ref } from 'vue';

/**
 * Global AI-agent drawer control.
 *
 * The drawer itself is mounted once in AppLayout, but any page needs to be
 * able to open it with a prefilled prompt (the "AI explain error" /
 * "AI explain code" / "AI update description" buttons). This module-level
 * composable is that bridge: pages call openAgentDrawer() with the context
 * and prompt they want pre-filled, and the drawer (via useAgentDrawer in
 * AppLayout + AgentDrawer) reacts to it.
 */

// Bumped every time a page asks to open the drawer with a prefilled prompt.
// A plain object ref would not retrigger for identical payloads, so the
// request carries a monotonically increasing id.
const request = ref(null);
let nextId = 0;

/**
 * Open the agent drawer and start a chat with a given context and message.
 *
 * @param {{ context?: string, message?: string }} payload
 */
export function openAgentDrawer(payload = {}) {
    request.value = { id: ++nextId, ...payload };
}

export function useAgentDrawer() {
    return {
        request,
        openAgentDrawer,
    };
}
