import { ref } from 'vue';

/**
 * Global AI-agent drawer control.
 *
 * The drawer itself is mounted once in AppLayout, but any page needs to be
 * able to open it with a prefilled prompt (the "AI explain error" /
 * "AI explain code" / "AI update description" buttons). This module-level
 * composable is that bridge: pages call openAgentDrawer() with the context
 * and prompt they want handed over, and the drawer (via useAgentDrawer in
 * AppLayout + AgentDrawer) reacts to it — it opens the new-chat form with
 * the prompt ready, and the chat only starts when the user clicks the
 * start button in the drawer.
 */

// Bumped every time a page asks to open the drawer with a prefilled prompt.
// A plain object ref would not retrigger for identical payloads, so the
// request carries a monotonically increasing id.
const request = ref(null);
let nextId = 0;

/**
 * Open the agent drawer on the new-chat form with a given context and
 * prompt. The chat itself is not started — the user clicks the start
 * button in the drawer to create it and send the message. Passing
 * `conversationId` instead opens an existing conversation (used by
 * the command palette's search results).
 *
 * @param {{ context?: string, message?: string, conversationId?: number }} payload
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
