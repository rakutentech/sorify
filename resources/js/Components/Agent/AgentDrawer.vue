<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeft, Bot, CircleAlert, Gauge, LoaderCircle, MessageSquarePlus, MessagesSquare,
    Send, Settings2, Square, Trash2, X,
} from '@lucide/vue';
import RunProgressCard from './RunProgressCard.vue';
import ToolCallSummary from './ToolCallSummary.vue';
import CopyButton from '@/Components/CopyButton.vue';
import { useAgentContext } from '@/composables/useAgentContext';
import { useAgentDrawer } from '@/composables/useAgentDrawer.js';
import { formatRelativeTime } from '@/utils/date';
import { MarkdownRenderer } from '@/Components/ui';

const { t } = useI18n();

const props = defineProps({
    show: { type: Boolean, required: true },
});

const emit = defineEmits(['close']);

// ── State ─────────────────────────────────────────────────────────────────────
const view = ref('list'); // list | new | chat

const profiles = ref([]);
const conversations = ref([]);
const loadingList = ref(false);
const loadingChat = ref(false);

// The open conversation (id, title, page_name, page_url, context, profile_id…)
const conversation = ref(null);
const messages = ref([]);
const models = ref([]);
const modelsError = ref(null);
const selectedModel = ref('');

const newChatForm = ref({ profile_id: null, context: '' });

const input = ref('');
const inputEl = ref(null);
const streaming = ref(false);
const deletingId = ref(null);
const deletingAll = ref(false);
const scrollContainer = ref(null);

// Agent mode: the per-conversation toggle for the full agent — tools,
// background execution bounded by the selected max run time. Off means
// plain Ask mode (no tools, quick answers). While an agent-mode turn is
// in flight, `backgroundTurn` shows the "running in the background" badge.
const agentMode = ref(false);
const agentMaxRun = ref(10);
const backgroundTurn = ref(false);

// Max run times offered, in minutes (must match the backend validation).
const AGENT_MAX_RUN_OPTIONS = [5, 10, 15, 30, 60];

// "Thinking…" state driven by the backend's `step` SSE events, shown
// while the LLM round-trip is in flight and no text is streaming yet.
const thinking = ref(false);
const stopping = ref(false);
const stepInfo = ref(null);

// Set while the backend waits out a provider rate limit before retrying
// (data: { seconds, attempt, max_attempts }) — cleared on any progress.
const waitingInfo = ref(null);

// Per-turn step budget (LLM tool-calling round-trips), picked in the chat
// box and sent with each request (must match the backend validation).
// Remembered across chats and page loads.
const MAX_STEPS_OPTIONS = [10, 25, 50, 100, 200, 500, 1000];

const maxSteps = ref(Number(localStorage.getItem('agent.max_steps')) || 100);

if (!MAX_STEPS_OPTIONS.includes(maxSteps.value)) maxSteps.value = 100;

watch(maxSteps, (value) => localStorage.setItem('agent.max_steps', String(value)));

let abortController = null;

// Run ids already tracked by a progress card in this conversation, so
// repeated `get_run_status` polls don't spawn duplicate cards.
const trackedRunIds = new Set();

// ── Session persistence ──────────────────────────────────────────────────────
// Keep the drawer's state (open + current chat) across page refreshes.
// sessionStorage, not localStorage: a new browser session starts clean.
const STORAGE_KEY = 'sorify_agent_drawer';

function readSavedState() {
    try {
        return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? 'null') ?? {};
    } catch {
        return {};
    }
}

function persistState() {
    const state = {
        open: props.show,
        view: view.value,
        conversationId: conversation.value?.id ?? null,
    };

    if (!state.open && !state.conversationId) {
        sessionStorage.removeItem(STORAGE_KEY);
    } else {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }
}

// Skip persistence while the mounted-restore below is in flight, so the
// props.show flip (parent reopens the drawer) doesn't clobber the stored
// conversationId before the async fetch finishes.
let restoring = false;

watch([() => props.show, view, () => conversation.value?.id], () => {
    if (!restoring) persistState();
});

onMounted(() => {
    const saved = readSavedState();

    if (saved.mode === 'ask' || saved.mode === 'agent') {
        // Legacy sessions stored the removed per-message Ask/Agent toggle.
        sessionStorage.removeItem(STORAGE_KEY);
    }

    if (!saved.conversationId) return;

    // Re-open the last chat in the background (fetches its messages). If it
    // no longer exists (deleted / different user session) the fetch fails
    // and the drawer stays on the conversation list.
    restoring = true;
    openConversation(saved.conversationId)
        .catch(() => {})
        .finally(() => {
            restoring = false;
            persistState();
        });
});

const { agentContext } = useAgentContext();
const { request: drawerRequest } = useAgentDrawer();

// Admin kill-switch: agents disabled for this account — block starting or
// continuing chats (the backend rejects these calls too).
const page = usePage();
const adminDisabled = computed(() => page.props.auth?.user?.agent_disabled === true);

const selectedProfile = computed(() =>
    profiles.value.find((profile) => profile.id === newChatForm.value.profile_id));

/**
 * Prompt the user can copy and paste into a local coding agent (Claude,
 * Cursor, Codex) that has the Sorify MCP server connected, so it picks the
 * conversation up and continues it. Kept in English: it is an LLM prompt,
 * not UI copy.
 */
const mcpHandoffPrompt = computed(() => {
    const conversationId = conversation.value?.id;

    if (!conversationId) return '';

    const title = conversation.value?.title ? ` "${conversation.value.title}"` : '';
    const page = conversation.value?.page_name ? ` (started from ${conversation.value.page_name})` : '';

    return `Continue my Sorify AI agent chat${title}${page}: read it with the Sorify MCP tool get_agent_conversation using {"conversation_id": ${conversationId}}, then continue from where it left off.\n\n/sorify:gateway`;
});

const SUGGESTIONS = computed(() => [
    t('agent.chat.suggestionCrawl', { url: 'https://example.com' }),
    t('agent.chat.suggestionReview'),
    t('agent.chat.suggestionRun'),
]);

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Auto-grow the chat input: starts at one row, expands with content, and
 * caps at a fixed height (CSS max-h-40) — after that it scrolls internally.
 */
function autoResizeInput() {
    const el = inputEl.value;

    if (!el) return;

    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
}

watch(input, () => nextTick(autoResizeInput));

// ── Data loading ──────────────────────────────────────────────────────────────
async function loadConversations() {
    loadingList.value = true;

    try {
        const response = await fetch('/sorify/agent/conversations', { headers: { Accept: 'application/json' } });

        const data = await response.json();
        conversations.value = data.conversations ?? [];
    } finally {
        loadingList.value = false;
    }
}

async function loadProfiles() {
    const response = await fetch('/sorify/agent/profiles', { headers: { Accept: 'application/json' } });

    const data = await response.json();
    profiles.value = data.profiles ?? [];

    if (!newChatForm.value.profile_id && profiles.value.length > 0) {
        newChatForm.value.profile_id = profiles.value[0].id;
    }
}

async function loadModels(profileId) {
    models.value = [];
    modelsError.value = null;

    if (!profileId) return;

    try {
        const response = await fetch(`/sorify/agent/models?profile_id=${profileId}`, { headers: { Accept: 'application/json' } });

        const data = await response.json();
        models.value = data.models ?? [];
        modelsError.value = data.error ?? null;

        if (models.value.length > 0) {
            const profile = profiles.value.find((p) => p.id === profileId);
            const preferred = profile?.default_model;
            selectedModel.value = models.value.includes(preferred) ? preferred : models.value[0];
        }
    } catch (error) {
        modelsError.value = error.message;
    }
}

// ── Conversation lifecycle ────────────────────────────────────────────────────
function startNewChat() {
    if (adminDisabled.value) return;

    if (profiles.value.length === 0) {
        view.value = 'new';

        return;
    }

    newChatForm.value.context = defaultContextText();
    view.value = 'new';

    // Pull the selected profile's model list so the model can be picked up
    // front (defaults to the profile's default model).
    if (newChatForm.value.profile_id && models.value.length === 0) {
        loadModels(newChatForm.value.profile_id);
    }
}

// Switching profiles in the new-chat form swaps in that profile's models.
watch(() => newChatForm.value.profile_id, (profileId) => {
    if (view.value === 'new' && profileId) {
        models.value = [];
        loadModels(profileId);
    }
});

/**
 * Prefill the editable context from the current page. Pages that registered
 * rich context provide structured text; otherwise a minimal pointer to the
 * current route.
 */
function defaultContextText() {
    const ctx = agentContext.value;

    if (ctx?.context) return ctx.context;

    return t('agent.newChat.defaultContext', { page: ctx.pageName, url: ctx.pageUrl });
}

async function createConversation(options = {}) {
    if (adminDisabled.value) return;

    const ctx = agentContext.value;

    const response = await fetch('/sorify/agent/conversations', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
            profile_id: newChatForm.value.profile_id,
            page_url: ctx.pageUrl,
            page_name: ctx.pageName,
            context: newChatForm.value.context,
            agent_mode: options.agentMode === true,
        }),
    });

    const data = await response.json();

    if (!response.ok || !data.conversation) {
        view.value = 'new';

        return;
    }

    conversation.value = data.conversation;
    messages.value = [];
    trackedRunIds.clear();
    view.value = 'chat';

    // Sync the mode toggle with what the server stored — chats started
    // from the AI buttons are created in agent mode.
    agentMode.value = !!data.conversation.agent_mode;

    // Models were already loaded for the picked profile in the new-chat
    // form (with the chosen model) — only fetch if that failed or was skipped.
    if (models.value.length === 0) {
        loadModels(newChatForm.value.profile_id);
    }
}

async function openConversation(id) {
    loadingChat.value = true;

    try {
        const response = await fetch(`/sorify/agent/conversations/${id}/messages`, { headers: { Accept: 'application/json' } });

        const data = await response.json();
        conversation.value = data.conversation;
        newChatForm.value.profile_id = data.conversation.profile_id;
        trackedRunIds.clear();
        messages.value = groupMessages(data.messages ?? []);
        view.value = 'chat';
        loadModels(data.conversation.profile_id);

        agentMode.value = !!data.conversation.agent_mode;
        agentMaxRun.value = data.conversation.agent_max_run_minutes ?? 10;

        // A turn is still running in the background —
        // pick up its event stream where it left off. Only while the
        // drawer is actually open: the stream is a long-lived SSE
        // request, and holding one while the drawer is closed would pin
        // a web worker (the whole dev server, if it runs a single
        // worker) for the whole turn. Re-opening the conversation picks
        // the stream back up.
        const active = data.conversation.active_turn;

        if (active && props.show) {
            streaming.value = true;
            backgroundTurn.value = agentMode.value;
            // A stop was already requested before the re-attach — show
            // "stopping" instead of "thinking" while the job ends the
            // turn at its next cancellation check.
            stopping.value = active.cancel_requested === true;
            thinking.value = !stopping.value;
            abortController = new AbortController();
            followTurn(active.turn_id, active.last_seq);
        }
    } finally {
        loadingChat.value = false;
    }
}

async function deleteConversation(id) {
    if (! confirm(t('agent.chat.confirmDelete'))) return;

    deletingId.value = id;

    try {
        await fetch(`/sorify/agent/conversations/${id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });

        if (conversation.value?.id === id) {
            conversation.value = null;
            messages.value = [];
            view.value = 'list';
        }

        loadConversations();
    } finally {
        deletingId.value = null;
    }
}

async function deleteAllConversations() {
    if (conversations.value.length === 0) return;
    if (! confirm(t('agent.list.confirmDeleteAll', { count: conversations.value.length }))) return;

    deletingAll.value = true;

    try {
        await fetch('/sorify/agent/conversations', {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });

        conversation.value = null;
        messages.value = [];
        conversations.value = [];

        loadConversations();
    } finally {
        deletingAll.value = false;
    }
}

function backToList() {
    if (streaming.value) detach();

    view.value = 'list';
    loadConversations();
}

// ── Transcript handling ──────────────────────────────────────────────────────
/**
 * Persisted rows are user / assistant / tool. Tool rows are folded into the
 * assistant message that requested them (matched by tool_call_id) so each
 * bubble owns its tool chips.
 */
function groupMessages(rows) {
    const grouped = [];

    for (const row of rows) {
        if (row.role === 'user') {
            grouped.push({ role: 'user', content: row.content ?? '', toolCalls: [] });
        } else if (row.role === 'assistant') {
            grouped.push({
                role: 'assistant',
                content: row.content ?? '',
                toolCalls: (row.tool_calls ?? []).map((call) => ({
                    id: call.id,
                    name: call.function?.name ?? '',
                    arguments: safeParse(call.function?.arguments),
                    result: null,
                    isError: false,
                })),
                runIds: [],
            });
        } else if (row.role === 'tool') {
            const parent = [...grouped].reverse().find(
                (message) => message.role === 'assistant'
                    && message.toolCalls.some((call) => call.id === row.tool_call_id),
            );

            if (parent) {
                const call = parent.toolCalls.find((c) => c.id === row.tool_call_id);
                call.result = row.content ?? '';

                // Re-attach run progress cards for runs mentioned in past
                // turns (e.g. a run triggered earlier in this conversation).
                const runId = extractRunId(row.name, row.content);

                if (runId !== null) trackRun(runId, parent);
            }
        }
    }

    return grouped;
}

function safeParse(json) {
    if (!json) return {};

    try {
        const parsed = JSON.parse(json);

        return typeof parsed === 'object' && parsed !== null ? parsed : {};
    } catch {
        return {};
    }
}

/**
 * Tools whose result is a run payload — used to attach a live progress
 * card to the message that produced it.
 */
const RUN_TOOLS = ['trigger_run', 'get_run_status', 'get_run'];

function extractRunId(name, result) {
    if (!RUN_TOOLS.includes(name) || typeof result !== 'string') return null;

    try {
        const parsed = JSON.parse(result);
        const id = parsed?.run_id;

        return Number.isInteger(id) ? id : null;
    } catch {
        return null;
    }
}

function trackRun(runId, message) {
    if (trackedRunIds.has(runId)) return;

    trackedRunIds.add(runId);
    message.runIds.push(runId);
}

function currentAssistant() {
    const last = messages.value[messages.value.length - 1];

    if (last && last.role === 'assistant' && last.streaming) return last;

    const message = { role: 'assistant', content: '', toolCalls: [], runIds: [], streaming: true };
    messages.value.push(message);

    return message;
}

function handleEvent(event, data) {
    if (event === 'delta') {
        thinking.value = false;
        waitingInfo.value = null;
        currentAssistant().content += data.text;
    } else if (event === 'step') {
        // A new LLM round-trip is starting — show the thinking state.
        thinking.value = true;
        waitingInfo.value = null;
        stepInfo.value = data;
    } else if (event === 'waiting') {
        // The provider rate-limited us; the backend is backing off and
        // will retry — show why the turn is paused instead of failing.
        thinking.value = true;
        waitingInfo.value = data;
    } else if (event === 'tool_start') {
        thinking.value = false;
        waitingInfo.value = null;
        currentAssistant().toolCalls.push({
            id: data.id,
            name: data.name,
            arguments: data.arguments ?? {},
            result: null,
            isError: false,
            startedAt: Date.now(),
        });
    } else if (event === 'tool_result') {
        const assistant = currentAssistant();
        const call = assistant.toolCalls.find((c) => c.id === data.id);

        if (call) {
            call.result = data.result;
            call.isError = !!data.is_error;
        }

        const runId = extractRunId(data.name, data.result);

        if (runId !== null) trackRun(runId, assistant);
    } else if (event === 'done') {
        thinking.value = false;
        waitingInfo.value = null;
        stopping.value = false;
        backgroundTurn.value = false;
        currentAssistant().streaming = false;
    } else if (event === 'error') {
        thinking.value = false;
        waitingInfo.value = null;
        stopping.value = false;
        backgroundTurn.value = false;
        currentAssistant().streaming = false;
        messages.value.push({ role: 'error', content: data.message, toolCalls: [] });
    }
}

// ── Chat streaming ───────────────────────────────────────────────────────────
/**
 * Read one SSE response, dispatching each event block to onEvent.
 * Returns whether a terminal event (`done` / `error`) was seen and the
 * last `id:` seq observed, so callers can reconnect with `after=seq`.
 */
async function readSseStream(response, onEvent) {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let terminal = false;
    let lastSeq = 0;

    while (true) {
        const { done, value } = await reader.read();

        if (done) break;

        buffer += decoder.decode(value, { stream: true });

        let boundary;
        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const block = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);

            let event = 'message';
            let payload = '';

            for (const line of block.split('\n')) {
                if (line.startsWith('event: ')) {
                    event = line.slice(7).trim();
                } else if (line.startsWith('data: ')) {
                    payload += line.slice(6);
                } else if (line.startsWith('id: ')) {
                    const seq = parseInt(line.slice(4), 10);

                    if (Number.isFinite(seq)) lastSeq = seq;
                }
            }

            if (payload !== '') {
                onEvent(event, JSON.parse(payload));

                if (event === 'done' || event === 'error') terminal = true;
            }
        }
    }

    return { terminal, lastSeq };
}

/**
 * Follow a turn's persisted events. The server closes the stream at
 * its wall-clock cap while the turn itself keeps running in the job —
 * so unless a terminal event was seen, reconnect with the last seq and
 * keep following.
 */
async function followTurn(turnId, after) {
    let cursor = after;

    while (true) {
        if (abortController?.signal.aborted) return;

        let response;

        try {
            response = await fetch(`/sorify/agent/conversations/${conversation.value.id}/turns/${turnId}/events?after=${cursor}`, {
                headers: { Accept: 'text/event-stream' },
                signal: abortController?.signal,
            });
        } catch (error) {
            if (error.name !== 'AbortError') {
                messages.value.push({ role: 'error', content: error.message, toolCalls: [] });
            }

            return;
        }

        if (!response.ok || !response.body) {
            const text = await response.text();

            messages.value.push({ role: 'error', content: text || `Request failed (${response.status})`, toolCalls: [] });

            return;
        }

        const { terminal, lastSeq } = await readSseStream(response, handleEvent);

        if (terminal || abortController?.signal.aborted) return;

        // The server-side stream cap hit while the turn still runs in the
        // background — resume from where it left off.
        cursor = lastSeq || cursor;

        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
}

async function send() {
    const message = input.value.trim();

    if (message === '' || streaming.value || !conversation.value || adminDisabled.value) return;

    input.value = '';
    messages.value.push({ role: 'user', content: message, toolCalls: [] });

    streaming.value = true;
    thinking.value = false;
    stopping.value = false;
    stepInfo.value = null;
    waitingInfo.value = null;
    abortController = new AbortController();

    try {
        const response = await fetch(`/sorify/agent/conversations/${conversation.value.id}/chat`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ message, model: selectedModel.value || null, max_steps: maxSteps.value }),
            signal: abortController.signal,
        });

        if (!response.ok || !response.body) {
            const text = await response.text();

            messages.value.push({ role: 'error', content: text || `Request failed (${response.status})`, toolCalls: [] });

            return;
        }

        // The turn runs in a background job — the client
        // alike — and the client follows its persisted events. The
        // follow stream heartbeats while the job works, so long LLM
        // round-trips and tool runs can't idle the connection out (a
        // direct in-request stream goes quiet during them and dies).
        backgroundTurn.value = agentMode.value;
        thinking.value = true;

        const data = await response.json();

        await followTurn(data.turn_id, 0);
    } catch (error) {
        if (error.name !== 'AbortError') {
            messages.value.push({ role: 'error', content: error.message, toolCalls: [] });
        }
    } finally {
        streaming.value = false;
        thinking.value = false;
        waitingInfo.value = null;
        backgroundTurn.value = false;
        abortController = null;
        refreshConversationTitle();
    }
}

// ── Agent mode ─────────────────────────────────────────────────────────────────
/**
 * Toggle Agent mode for this conversation (off by default). When on,
 * turns run with the full tool set in a background job bounded by the
 * selected max run time, and keep going even if this window is closed.
 * Off means plain Ask mode — quick, tool-free answers.
 */
async function toggleAgentMode() {
    if (!conversation.value || streaming.value) return;

    agentMode.value = !agentMode.value;

    try {
        await fetch(`/sorify/agent/conversations/${conversation.value.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ agent_mode: agentMode.value }),
        });
    } catch {
        agentMode.value = !agentMode.value;
    }
}

/** Persist the selected max run time (minutes) for agent-mode turns. */
async function saveAgentMaxRun() {
    if (!conversation.value) return;

    try {
        await fetch(`/sorify/agent/conversations/${conversation.value.id}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ agent_max_run_minutes: agentMaxRun.value }),
        });
    } catch {
        // Keep the local value; the next successful save will resync.
    }
}

/**
 * Detach from the running turn without stopping it: abort the stream /
 * event follow and settle the in-flight bubble. Used when the drawer
 * closes or the user navigates back to the conversation list — a
 * agent-mode turn must survive that and keep running in the queue.
 */
function detach() {
    abortController?.abort();
    stopping.value = false;
    finalizeInflightAssistant();
}

/**
 * The user clicked Stop: detach AND ask the server to stop the turn
 * itself (an in-flight job, even in the middle of a tool
 * run) — aborting the fetch alone leaves the turn running server-side.
 */
async function stop() {
    abortController?.abort();

    // Flip the indicator right away — the server ends the turn at its
    // next cancellation check (within ~1s while streaming, at the next
    // step boundary during a tool run).
    stopping.value = true;
    thinking.value = false;
    waitingInfo.value = null;

    if (conversation.value) {
        try {
            await fetch(`/sorify/agent/conversations/${conversation.value.id}/cancel-turn`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            });
        } catch {
            // Fire-and-forget: the abort above already reset the UI.
        }
    }

    finalizeInflightAssistant();
}

/**
 * Mark the in-flight assistant bubble as finished — after an abort or a
 * cut-off stream, no terminal event arrives to clear its streaming
 * state, so it would look like it is still generating.
 */
function finalizeInflightAssistant() {
    const last = messages.value[messages.value.length - 1];

    if (last?.role === 'assistant' && last.streaming) {
        last.streaming = false;
    }
}

/** Pull the latest title after a turn (it is derived from the first message). */
async function refreshConversationTitle() {
    if (!conversation.value) return;

    const response = await fetch(`/sorify/agent/conversations/${conversation.value.id}/messages`, { headers: { Accept: 'application/json' } });

    if (!response.ok) return;

    const data = await response.json();
    conversation.value.title = data.conversation?.title ?? conversation.value.title;
}

// ── Wiring ────────────────────────────────────────────────────────────────────
watch(() => props.show, (show) => {
    if (show) {
        view.value = 'list';
        loadConversations();
        loadProfiles();
        applyDrawerRequest();
    } else {
        detach();
    }
});

// A page (an "AI explain error" / "AI explain code" / "AI update
// description" button) asked to open the drawer and start a chat with a
// given context and message. The chat is started right away — the
// conversation is created and the message sent without showing the
// new-chat form. AppLayout opens the drawer on the same request;
// applyDrawerRequest() is idempotent per request id so whichever watcher
// runs last wins cleanly.
let appliedRequestId = 0;

async function applyDrawerRequest() {
    const request = drawerRequest.value;

    if (!request || request.id === appliedRequestId || !props.show || adminDisabled.value) return;

    appliedRequestId = request.id;

    // The command palette asked for an existing conversation — open it
    // directly instead of starting a new chat.
    if (request.conversationId) {
        await openConversation(request.conversationId).catch(() => {});

        return;
    }

    newChatForm.value.context = request.context ?? defaultContextText();

    // Profiles may still be loading (the drawer was opened by this very
    // request) — wait for them so a profile can be picked automatically.
    if (profiles.value.length === 0) {
        await loadProfiles();
    }

    // No profile configured (or creating the conversation failed) — fall
    // back to the new-chat view, which carries the setup hint.
    if (profiles.value.length === 0) {
        view.value = 'new';

        return;
    }

    if (!newChatForm.value.profile_id) {
        newChatForm.value.profile_id = profiles.value[0].id;
    }

    // The AI buttons ("explain error", "explain code", …) imply real
    // agent work — tools, multi-step — so the conversation they start is
    // always created in agent mode.
    await createConversation({ agentMode: true });

    // Conversation started — send the message straight away. Falls back to
    // a pre-filled input when creating it failed, so nothing is lost.
    if (request.message && view.value !== 'new') {
        if (conversation.value && !streaming.value) {
            input.value = request.message;
            await send();
        }
    } else if (request.message) {
        input.value = request.message;
        nextTick(autoResizeInput);
    }
}

watch(drawerRequest, () => {
    if (props.show) applyDrawerRequest();
});

watch(messages, () => {
    nextTick(() => {
        const el = scrollContainer.value;

        if (el) el.scrollTop = el.scrollHeight;
    });
}, { deep: true });
</script>

<template>
    <Teleport to="body">
        <!-- Scrim only below lg — at lg+ the layout is pushed aside instead,
             so the page content stays visible and interactive beside the chat -->
        <div
            v-if="show"
            class="fixed inset-0 z-50 bg-[var(--md-sys-color-scrim)]/60 lg:hidden"
            @click="emit('close')"
        />
        <div
            v-if="show"
            class="fixed top-0 right-0 bottom-0 z-50 w-full max-w-xl bg-[var(--md-sys-color-surface-container-high)] shadow-elevation-3 flex flex-col border-l border-[var(--md-sys-color-outline-variant)]"
            role="dialog"
            :aria-label="t('agent.chat.title')"
        >
            <!-- Header -->
            <div class="flex items-center gap-2 px-4 py-3 border-b border-[var(--md-sys-color-outline-variant)] flex-shrink-0">
                <button
                    v-if="view !== 'list'"
                    class="p-1.5 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                    :title="t('agent.chat.back')"
                    @click="backToList"
                >
                    <ArrowLeft :size="18" />
                </button>
                <Bot :size="20" :style="{ color: 'var(--md-sys-color-tertiary)' }" />
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex-1 truncate">
                    {{ view === 'chat' && conversation ? conversation.title : t('agent.chat.title') }}
                </h2>

                <select
                    v-if="view === 'chat' && models.length > 0"
                    v-model="selectedModel"
                    class="md-body-small bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-extra-small)] px-2 py-1.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] max-w-44"
                    :title="t('agent.chat.model')"
                >
                    <option v-for="model in models" :key="model" :value="model">{{ model }}</option>
                </select>

                <button
                    v-if="view === 'chat' && conversation"
                    class="p-1.5 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors"
                    :title="t('agent.chat.delete')"
                    :disabled="deletingId === conversation.id || streaming"
                    @click="deleteConversation(conversation.id)"
                >
                    <Trash2 :size="18" />
                </button>
                <Link
                    href="/sorify/profile"
                    :data="{ section: 'agents' }"
                    class="p-1.5 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                    :title="t('agent.chat.openSettings')"
                    @click="emit('close')"
                >
                    <Settings2 :size="18" />
                </Link>
                <button
                    class="p-1.5 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                    :title="t('common.close')"
                    @click="emit('close')"
                >
                    <X :size="18" />
                </button>
            </div>

            <!-- Admin kill-switch: agents disabled for this account -->
            <div
                v-if="adminDisabled"
                class="flex items-start gap-2.5 px-4 py-3 border-b border-[var(--md-sys-color-outline-variant)] bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)] flex-shrink-0"
            >
                <CircleAlert :size="16" class="flex-shrink-0 mt-0.5" />
                <p class="md-body-small">{{ t('agent.disabledByAdmin') }}</p>
            </div>

            <!-- Conversations list -->
            <div v-if="view === 'list'" class="flex-1 overflow-y-auto slim-scrollbar min-h-0 px-4 py-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface-variant)] flex items-center gap-2.5">
                        {{ t('agent.list.recent') }}
                        <button
                            v-if="!adminDisabled && conversations.length > 0"
                            class="inline-flex items-center gap-1 md-label-small px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-sys-color-error)] hover:bg-[var(--md-sys-color-error-container)] hover:text-[var(--md-sys-color-on-error-container)] transition-colors disabled:opacity-50"
                            :disabled="deletingAll || deletingId !== null"
                            :title="t('agent.list.deleteAllTitle')"
                            @click="deleteAllConversations"
                        >
                            <LoaderCircle v-if="deletingAll" :size="12" class="animate-spin" />
                            <Trash2 v-else :size="12" />
                            {{ t('agent.list.deleteAll') }}
                        </button>
                    </span>
                    <button
                        v-if="!adminDisabled"
                        class="inline-flex items-center gap-1.5 md-label-large px-3 py-1.5 rounded-[var(--md-sys-shape-corner-full)] bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)] hover:opacity-90 transition-opacity"
                        @click="startNewChat"
                    >
                        <MessageSquarePlus :size="16" />
                        {{ t('agent.list.newChat') }}
                    </button>
                </div>

                <div v-if="loadingList" class="flex justify-center py-8">
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.list.loading') }}</span>
                </div>

                <div
                    v-else-if="profiles.length === 0"
                    class="md-body-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-highest)] rounded-[var(--md-sys-shape-corner-medium)] px-4 py-3"
                >
                    {{ t('agent.list.noProfiles') }}
                    <a href="/sorify/profile?section=agents" class="underline">{{ t('agent.list.goToProfiles') }}</a>
                </div>

                <div v-else-if="conversations.length === 0" class="flex flex-col items-center py-12 text-center">
                    <MessagesSquare :size="32" class="text-[var(--md-sys-color-on-surface-variant)] mb-3" />
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.list.empty') }}</p>
                </div>

                <ul v-else class="space-y-1.5">
                    <li
                        v-for="item in conversations"
                        :key="item.id"
                        class="group/conversation flex items-center gap-1 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] hover:bg-[var(--md-sys-color-surface-container)] transition-colors"
                    >
                        <button
                            class="flex-1 min-w-0 text-left px-4 py-3"
                            :title="item.title"
                            @click="openConversation(item.id)"
                        >
                            <div class="flex items-center gap-2">
                                <p class="md-label-large text-[var(--md-sys-color-on-surface)] flex-1 truncate">{{ item.title }}</p>
                                <span
                                    v-if="item.profile_name"
                                    class="md-label-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container)] px-2 py-0.5 rounded-full flex-shrink-0"
                                >{{ item.profile_name }}</span>
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] truncate">
                                {{ item.page_name ? `${item.page_name} · ` : '' }}{{ formatRelativeTime(item.updated_at) }}
                            </p>
                        </button>
                        <button
                            class="p-2 mr-2 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-error-container)] hover:text-[var(--md-sys-color-on-error-container)] transition-colors flex-shrink-0"
                            :title="t('agent.list.deleteOne')"
                            @click.stop="deleteConversation(item.id)"
                        >
                            <LoaderCircle v-if="deletingId === item.id" :size="14" class="animate-spin" />
                            <Trash2 v-else :size="14" />
                        </button>
                    </li>
                </ul>
            </div>

            <!-- New chat -->
            <div v-else-if="view === 'new'" class="flex-1 overflow-y-auto slim-scrollbar min-h-0 px-4 py-4 space-y-4">
                <div
                    v-if="profiles.length === 0"
                    class="md-body-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-highest)] rounded-[var(--md-sys-shape-corner-medium)] px-4 py-3"
                >
                    {{ t('agent.list.noProfiles') }}
                    <a href="/sorify/profile?section=agents" class="underline">{{ t('agent.list.goToProfiles') }}</a>
                </div>

                <label v-if="profiles.length > 0" class="block">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.newChat.profile') }}</span>
                    <select
                        v-model="newChatForm.profile_id"
                        class="mt-1 w-full md-body-large bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-extra-small)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                    >
                        <option v-for="profile in profiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                    </select>
                </label>

                <label v-if="models.length > 0" class="block">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.newChat.model') }}</span>
                    <select
                        v-model="selectedModel"
                        class="mt-1 w-full md-body-large bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-extra-small)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                    >
                        <option v-for="model in models" :key="model" :value="model">{{ model }}</option>
                    </select>
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.newChat.modelHint') }}</span>
                </label>

                <div class="md-body-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-highest)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5">
                    {{ t('agent.newChat.pageIs', { page: agentContext.pageName, url: agentContext.pageUrl }) }}
                </div>

                <label class="block">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.newChat.context') }}</span>
                    <textarea
                        v-model="newChatForm.context"
                        rows="8"
                        class="mt-1 w-full md-body-medium bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] resize-y"
                        :placeholder="t('agent.newChat.contextPlaceholder')"
                    />
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.newChat.contextHint') }}</span>
                </label>

                <button
                    class="inline-flex items-center gap-1.5 md-label-large px-4 py-2 rounded-[var(--md-sys-shape-corner-full)] bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)] hover:opacity-90 transition-opacity disabled:opacity-50"
                    :disabled="profiles.length === 0"
                    @click="createConversation"
                >
                    <MessageSquarePlus :size="16" />
                    {{ t('agent.newChat.start') }}
                </button>
            </div>

            <!-- Chat -->
            <template v-else>
                <div
                    v-if="conversation && mcpHandoffPrompt"
                    class="px-4 py-2 border-b border-[var(--md-sys-color-outline-variant)] flex-shrink-0"
                >
                    <CopyButton
                        :value="mcpHandoffPrompt"
                        :label="t('agent.chat.copyPrompt')"
                        :title="mcpHandoffPrompt"
                        class="w-full justify-center"
                    />
                </div>

                <div ref="scrollContainer" class="flex-1 overflow-y-auto slim-scrollbar min-h-0 px-4 py-4 space-y-3">
                    <p
                        v-if="backgroundTurn"
                        class="md-label-small inline-flex items-center gap-1.5 text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary-container)] rounded-[var(--md-sys-shape-corner-full)] px-3 py-1"
                    >
                        <Bot :size="12" />
                        {{ t('agent.chat.agentRunning', { minutes: agentMaxRun }) }}
                    </p>

                    <p
                        v-if="modelsError && models.length === 0"
                        class="md-body-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-highest)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5"
                    >
                        {{ t('agent.chat.modelsError', { error: modelsError }) }}
                    </p>

                    <div v-if="loadingChat" class="flex justify-center py-8">
                        <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.chat.loading') }}</span>
                    </div>

                    <div v-else-if="messages.length === 0" class="flex flex-col items-center justify-center py-12 text-center">
                        <Bot :size="32" :style="{ color: 'var(--md-sys-color-tertiary)' }" class="mb-3" />
                        <p class="md-body-large text-[var(--md-sys-color-on-surface)] mb-1">{{ t('agent.chat.emptyTitle') }}</p>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-4 max-w-sm">{{ t('agent.chat.emptyBody') }}</p>
                        <div class="flex flex-col gap-2 w-full max-w-sm">
                            <button
                                v-for="suggestion in SUGGESTIONS"
                                :key="suggestion"
                                class="md-body-small text-left text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-highest)] hover:bg-[var(--md-sys-color-surface-container)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 transition-colors"
                                @click="input = suggestion"
                            >
                                {{ suggestion }}
                            </button>
                        </div>
                    </div>

                    <template v-else>
                        <div
                            v-for="(message, index) in messages"
                            :key="index"
                            class="flex flex-col"
                            :class="message.role === 'user' ? 'items-end' : 'items-start'"
                        >
                        <div
                            v-if="message.content !== '' && message.role === 'user'"
                            class="max-w-[85%] md-body-medium whitespace-pre-wrap break-words rounded-[var(--md-sys-shape-corner-medium)] px-3.5 py-2.5 bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]"
                        >
                            {{ message.content }}
                        </div>
                        <div
                            v-else-if="message.content !== '' && message.role !== 'error'"
                            class="max-w-[92%] w-fit rounded-[var(--md-sys-shape-corner-medium)] px-3.5 py-2.5 bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)]"
                        >
                            <MarkdownRenderer :content="message.content" />
                            <span
                                v-if="message.streaming && message.toolCalls.length === 0"
                                class="inline-block w-1.5 h-4 ml-0.5 align-text-bottom bg-[var(--md-sys-color-primary)] animate-pulse"
                            />
                        </div>
                            <div
                                v-if="message.role === 'error'"
                                class="max-w-[85%] md-body-small rounded-[var(--md-sys-shape-corner-medium)] px-3.5 py-2.5 bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)] flex items-start gap-2"
                            >
                                <CircleAlert :size="14" class="flex-shrink-0 mt-0.5" />
                                <span class="whitespace-pre-wrap break-words">{{ message.content }}</span>
                            </div>
                            <ToolCallSummary
                                v-if="message.toolCalls.length"
                                :tool-calls="message.toolCalls"
                                class="w-full max-w-[92%] mt-1.5"
                            />
                            <div v-if="message.runIds?.length" class="w-full max-w-[92%] space-y-1.5 mt-1.5">
                                <RunProgressCard v-for="id in message.runIds" :key="id" :run-id="id" />
                            </div>
                        </div>
                    </template>

                    <!-- Thinking indicator: shown while an LLM round-trip is
                         in flight and no text is streaming yet -->
                    <div v-if="streaming && thinking" class="flex items-center gap-2.5 px-1 py-1">
                        <span class="flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-[var(--md-sys-color-primary)] animate-pulse"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[var(--md-sys-color-primary)] animate-pulse [animation-delay:150ms]"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[var(--md-sys-color-primary)] animate-pulse [animation-delay:300ms]"></span>
                        </span>
                        <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ stopping ? t('agent.chat.stopping') : t('agent.chat.thinking') }}</span>
                        <span v-if="waitingInfo && !stopping" class="md-body-small text-[var(--md-sys-color-error)]">
                            {{ t('agent.chat.rateLimitedWait', { seconds: waitingInfo.seconds, attempt: waitingInfo.attempt, max: waitingInfo.max_attempts }) }}
                        </span>
                        <span v-else-if="stepInfo?.max_steps && !stopping" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-70">
                            {{ t('agent.chat.stepCount', { step: stepInfo.step, max: stepInfo.max_steps }) }}
                        </span>
                    </div>
                </div>

                <!-- Input -->
                <div class="border-t border-[var(--md-sys-color-outline-variant)] flex-shrink-0">
                    <!-- Agent mode toggle + step / max-run-time options -->
                    <div class="flex items-center gap-1 px-4 pt-2.5 flex-wrap">
                        <button
                            class="md-label-small inline-flex items-center gap-1 px-2.5 py-1 rounded-full transition-colors disabled:opacity-50"
                            :class="agentMode
                                ? 'bg-[var(--md-sys-color-primary)] text-[var(--md-sys-color-on-primary)]'
                                : 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-highest)]'"
                            :title="t('agent.chat.agentModeTitle')"
                            :disabled="streaming"
                            @click="toggleAgentMode"
                        >
                            <Bot :size="12" />
                            {{ t('agent.chat.agentMode') }}
                        </button>
                        <label class="inline-flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]" :title="t('agent.chat.maxStepsTitle')">
                            <Gauge :size="12" />
                            <select
                                v-model="maxSteps"
                                class="bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-full)] px-2 py-0.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] disabled:opacity-50"
                                :disabled="streaming"
                            >
                                <option v-for="steps in MAX_STEPS_OPTIONS" :key="steps" :value="steps">
                                    {{ t('agent.chat.maxSteps', { count: steps }) }}
                                </option>
                            </select>
                        </label>
                        <label v-if="agentMode" class="inline-flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                            <select
                                v-model="agentMaxRun"
                                class="bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-full)] px-2 py-0.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] disabled:opacity-50"
                                :title="t('agent.chat.maxRunTitle')"
                                :disabled="streaming"
                                @change="saveAgentMaxRun"
                            >
                                <option v-for="minutes in AGENT_MAX_RUN_OPTIONS" :key="minutes" :value="minutes">
                                    {{ t('agent.chat.maxRun', { minutes }) }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="flex items-end gap-2 px-4 py-3">
                        <textarea
                            ref="inputEl"
                            v-model="input"
                            rows="1"
                            class="flex-1 md-body-medium bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-extra-large)] px-3.5 py-3 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] resize-none min-h-[3rem] max-h-40 overflow-y-auto [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden"
                            :placeholder="t('agent.chat.placeholder')"
                            :disabled="streaming"
                            @keydown.enter.exact.prevent="send"
                        />
                        <button
                            v-if="streaming"
                            class="p-2.5 rounded-full bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            :title="t('agent.chat.stop')"
                            :disabled="stopping"
                            @click="stop"
                        >
                            <Square :size="16" />
                        </button>
                        <button
                            v-else
                            class="p-2.5 rounded-full transition-colors"
                            :class="input.trim() === ''
                                ? 'bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface-variant)] cursor-default'
                                : 'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]'"
                            :disabled="input.trim() === ''"
                            :title="t('agent.chat.send')"
                            @click="send"
                        >
                            <Send :size="16" />
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </Teleport>
</template>
