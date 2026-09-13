<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    ArrowLeft, Bot, CircleAlert, MessageSquarePlus, MessagesSquare,
    Send, Settings2, Square, Trash2, X,
} from '@lucide/vue';
import ToolCallChip from './ToolCallChip.vue';
import CopyButton from '@/Components/CopyButton.vue';
import { useAgentContext } from '@/composables/useAgentContext';
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
const scrollContainer = ref(null);

let abortController = null;

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

async function createConversation() {
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
        }),
    });

    const data = await response.json();

    conversation.value = data.conversation;
    messages.value = [];
    view.value = 'chat';

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
        messages.value = groupMessages(data.messages ?? []);
        view.value = 'chat';
        loadModels(data.conversation.profile_id);
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

function backToList() {
    if (streaming.value) stop();

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
            });
        } else if (row.role === 'tool') {
            const parent = [...grouped].reverse().find(
                (message) => message.role === 'assistant'
                    && message.toolCalls.some((call) => call.id === row.tool_call_id),
            );

            if (parent) {
                const call = parent.toolCalls.find((c) => c.id === row.tool_call_id);
                call.result = row.content ?? '';
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

function currentAssistant() {
    const last = messages.value[messages.value.length - 1];

    if (last && last.role === 'assistant' && last.streaming) return last;

    const message = { role: 'assistant', content: '', toolCalls: [], streaming: true };
    messages.value.push(message);

    return message;
}

function handleEvent(event, data) {
    if (event === 'delta') {
        currentAssistant().content += data.text;
    } else if (event === 'tool_start') {
        currentAssistant().toolCalls.push({
            id: data.id,
            name: data.name,
            arguments: data.arguments ?? {},
            result: null,
            isError: false,
        });
    } else if (event === 'tool_result') {
        const assistant = currentAssistant();
        const call = assistant.toolCalls.find((c) => c.id === data.id);

        if (call) {
            call.result = data.result;
            call.isError = !!data.is_error;
        }
    } else if (event === 'done') {
        currentAssistant().streaming = false;
    } else if (event === 'error') {
        currentAssistant().streaming = false;
        messages.value.push({ role: 'error', content: data.message, toolCalls: [] });
    }
}

// ── Chat streaming ───────────────────────────────────────────────────────────
async function send() {
    const message = input.value.trim();

    if (message === '' || streaming.value || !conversation.value) return;

    input.value = '';
    messages.value.push({ role: 'user', content: message, toolCalls: [] });

    streaming.value = true;
    abortController = new AbortController();

    try {
        const response = await fetch(`/sorify/agent/conversations/${conversation.value.id}/chat`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'text/event-stream',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ message, model: selectedModel.value || null }),
            signal: abortController.signal,
        });

        if (!response.ok || !response.body) {
            const text = await response.text();

            messages.value.push({ role: 'error', content: text || `Request failed (${response.status})`, toolCalls: [] });

            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

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
                    }
                }

                if (payload !== '') {
                    handleEvent(event, JSON.parse(payload));
                }
            }
        }
    } catch (error) {
        if (error.name !== 'AbortError') {
            messages.value.push({ role: 'error', content: error.message, toolCalls: [] });
        }
    } finally {
        streaming.value = false;
        abortController = null;
        refreshConversationTitle();
    }
}

function stop() {
    abortController?.abort();
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
    } else {
        stop();
    }
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

            <!-- Conversations list -->
            <div v-if="view === 'list'" class="flex-1 overflow-y-auto slim-scrollbar min-h-0 px-4 py-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.list.recent') }}</span>
                    <button
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
                    <li v-for="item in conversations" :key="item.id">
                        <button
                            class="w-full text-left rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] hover:bg-[var(--md-sys-color-surface-container)] px-4 py-3 transition-colors"
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
                    class="flex items-center gap-2.5 px-4 py-2 border-b border-[var(--md-sys-color-outline-variant)] flex-shrink-0"
                >
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)] truncate flex-1" :title="mcpHandoffPrompt">
                        {{ t('agent.chat.mcpHandoff') }}
                    </span>
                    <CopyButton :value="mcpHandoffPrompt" :label="t('agent.chat.copyPrompt')" />
                </div>

                <div ref="scrollContainer" class="flex-1 overflow-y-auto slim-scrollbar min-h-0 px-4 py-4 space-y-3">
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
                            <div v-if="message.toolCalls.length" class="w-full max-w-[92%] space-y-1.5 mt-1.5">
                                <ToolCallChip v-for="call in message.toolCalls" :key="call.id" :tool-call="call" />
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Input -->
                <div class="flex items-end gap-2 px-4 py-3 border-t border-[var(--md-sys-color-outline-variant)] flex-shrink-0">
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
                        class="p-2.5 rounded-full bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)] transition-colors"
                        :title="t('agent.chat.stop')"
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
            </template>
        </div>
    </Teleport>
</template>
