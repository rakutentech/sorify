<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    Activity, Bot, BookMarked, Cpu, FlaskConical, FolderKanban, LayoutDashboard, MessageSquare,
    PlayCircle, Search, ScrollText, ShieldCheck, Star, UserCircle, Workflow,
} from '@lucide/vue';
import { useCommandPalette } from '@/composables/useCommandPalette.js';
import { openAgentDrawer } from '@/composables/useAgentDrawer.js';
import { formatRelativeTime } from '@/utils/date';

const { t } = useI18n();
const page = usePage();
const { open, closeCommandPalette } = useCommandPalette();

const query = ref('');
const searching = ref(false);
const results = ref({ suites: [], tests: [], runs: [], conversations: [], skills: [] });
const selectedIndex = ref(0);
const inputEl = ref(null);
const listContainer = ref(null);

const user = computed(() => page.props.auth?.user ?? null);

// ── Static navigation entries ────────────────────────────────────────────────
const navigation = computed(() => {
    const links = [
        { label: t('nav.dashboard'), href: '/sorify/', icon: LayoutDashboard, accent: 'var(--md-sys-color-primary)' },
        { label: t('nav.feed'), href: '/sorify/feed', icon: Activity, accent: 'var(--md-ext-color-success)' },
        { label: t('nav.testSuites'), href: '/sorify/suites', icon: FolderKanban, accent: 'var(--md-sys-color-tertiary)' },
        { label: t('nav.bookmarks'), href: '/sorify/bookmarks', icon: Star, accent: 'var(--md-ext-color-warning)' },
        { label: t('nav.profile'), href: '/sorify/profile', icon: UserCircle, accent: 'var(--md-sys-color-primary)' },
    ];

    if (user.value?.is_admin) {
        links.push(
            { label: t('nav.users'), href: '/sorify/admin/users', icon: ShieldCheck, accent: 'var(--md-sys-color-error)' },
            { label: t('nav.agentRuns'), href: '/sorify/admin/agent-runs', icon: Bot, accent: 'var(--md-sys-color-primary)' },
            { label: t('nav.githubApps'), href: '/sorify/admin/github-apps', icon: Workflow, accent: 'var(--md-sys-color-error)' },
            { label: t('nav.system'), href: '/sorify/admin/system', icon: Cpu, accent: 'var(--md-sys-color-error)' },
            { label: t('nav.logs'), href: '/sorify/log-viewer', icon: ScrollText, accent: 'var(--md-sys-color-on-surface-variant)', external: true },
        );
    }

    return links;
});

/** Substring, or loose subsequence fallback ("ts" matches "Test Suites"). */
function fuzzyMatch(haystack, needle) {
    if (!needle) return true;
    if (haystack.includes(needle)) return true;

    let i = 0;

    for (const char of haystack) {
        if (char === needle[i]) i++;
        if (i === needle.length) return true;
    }

    return false;
}

// ── Result groups ───────────────────────────────────────────────────────────
const groups = computed(() => {
    const needle = query.value.trim().toLowerCase();

    const navItems = navigation.value
        .filter((link) => fuzzyMatch(link.label.toLowerCase(), needle))
        .map((link) => ({
            type: 'nav',
            id: `nav-${link.href}`,
            label: link.label,
            icon: link.icon,
            accent: link.accent,
            href: link.href,
            external: link.external === true,
        }));

    const groupsOut = [];

    if (navItems.length) {
        groupsOut.push({ key: 'navigation', label: t('commandPalette.groups.navigation'), items: navItems });
    }

    if (results.value.suites?.length) {
        groupsOut.push({
            key: 'suites',
            label: t('commandPalette.groups.suites'),
            items: results.value.suites.map((suite) => ({
                type: 'suite',
                id: `suite-${suite.id}`,
                label: suite.name,
                sublabel: suite.description,
                icon: FolderKanban,
                accent: 'var(--md-sys-color-tertiary)',
                href: `/sorify/suites/${suite.id}`,
            })),
        });
    }

    if (results.value.tests?.length) {
        groupsOut.push({
            key: 'tests',
            label: t('commandPalette.groups.tests'),
            items: results.value.tests.map((test) => ({
                type: 'test',
                id: `test-${test.id}`,
                label: test.name,
                sublabel: test.suite_name,
                icon: FlaskConical,
                accent: 'var(--md-sys-color-primary)',
                href: `/sorify/suites/${test.suite_id}/tests/${test.id}`,
            })),
        });
    }

    if (results.value.runs?.length) {
        groupsOut.push({
            key: 'runs',
            label: t('commandPalette.groups.runs'),
            items: results.value.runs.map((run) => ({
                type: 'run',
                id: `run-${run.id}`,
                label: `#${run.id} — ${run.suite_name ?? ''}`,
                sublabel: `${run.status} · ${formatRelativeTime(run.created_at)}`,
                icon: PlayCircle,
                accent: runStatusColor(run.status),
                href: `/sorify/runs/${run.id}`,
            })),
        });
    }

    if (results.value.conversations?.length) {
        groupsOut.push({
            key: 'conversations',
            label: t('commandPalette.groups.conversations'),
            items: results.value.conversations.map((conversation) => ({
                type: 'conversation',
                id: `conversation-${conversation.id}`,
                label: conversation.title || t('commandPalette.untitledConversation'),
                sublabel: [conversation.page_name, formatRelativeTime(conversation.updated_at)]
                    .filter(Boolean).join(' · '),
                icon: MessageSquare,
                accent: 'var(--md-sys-color-tertiary)',
                conversationId: conversation.id,
            })),
        });
    }

    if (results.value.skills?.length) {
        groupsOut.push({
            key: 'skills',
            label: t('commandPalette.groups.skills'),
            items: results.value.skills.map((skill) => ({
                type: 'skill',
                id: `skill-${skill.id}`,
                label: skill.name,
                sublabel: [skill.author_name, skill.description].filter(Boolean).join(' · '),
                icon: BookMarked,
                accent: 'var(--md-sys-color-tertiary)',
                // Your own skill lives on the profile page; one shared by
                // someone else deep-links into the browse page, pre-filtered.
                href: skill.owner
                    ? '/sorify/profile?section=skills'
                    : `/sorify/skills/browse?search=${encodeURIComponent(skill.name)}`,
            })),
        });
    }

    return groupsOut;
});

const flatItems = computed(() => groups.value.flatMap((group) => group.items));

const hasResults = computed(() => flatItems.value.length > 0);

function runStatusColor(status) {
    if (['passed', 'completed', 'active'].includes(status)) return 'var(--md-ext-color-success)';
    if (status === 'failed') return 'var(--md-sys-color-error)';
    if (['error', 'timeout'].includes(status)) return 'var(--md-ext-color-warning)';
    if (['running', 'pending'].includes(status)) return 'var(--md-sys-color-primary)';

    return 'var(--md-sys-color-outline-variant)';
}

// Keep the selection valid as the list changes (new results arrive).
watch(flatItems, (items) => {
    if (selectedIndex.value >= items.length) selectedIndex.value = Math.max(0, items.length - 1);
});

// ── Keyboard navigation ──────────────────────────────────────────────────────
function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();

        if (flatItems.value.length) selectedIndex.value = (selectedIndex.value + 1) % flatItems.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();

        if (flatItems.value.length) selectedIndex.value = (selectedIndex.value - 1 + flatItems.value.length) % flatItems.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();

        const item = flatItems.value[selectedIndex.value];

        if (item) select(item);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        closeCommandPalette();
    }
}

function scrollSelectionIntoView() {
    listContainer.value
        ?.querySelector(`[data-index="${selectedIndex.value}"]`)
        ?.scrollIntoView({ block: 'nearest' });
}

watch(selectedIndex, () => nextTick(scrollSelectionIntoView));

// ── Selection ───────────────────────────────────────────────────────────────
function select(item) {
    closeCommandPalette();

    if (item.type === 'conversation') {
        openAgentDrawer({ conversationId: item.conversationId });

        return;
    }

    if (item.external) {
        window.open(item.href, '_blank', 'noopener');

        return;
    }

    router.get(item.href);
}

// ── Server search (debounced, abortable) ────────────────────────────────────
let debounceTimer = null;
let controller = null;

watch(query, (value) => {
    clearTimeout(debounceTimer);

    const trimmed = value.trim();

    if (trimmed.length < 2) {
        results.value = { suites: [], tests: [], runs: [], conversations: [], skills: [] };
        searching.value = false;
        controller?.abort();

        return;
    }

    debounceTimer = setTimeout(() => search(trimmed), 300);
});

async function search(term) {
    controller?.abort();
    controller = new AbortController();

    searching.value = true;

    try {
        const response = await fetch(`/sorify/search?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });

        results.value = await response.json();
    } catch (error) {
        if (error.name !== 'AbortError') {
            results.value = { suites: [], tests: [], runs: [], conversations: [], skills: [] };
        }
    } finally {
        // An aborted request that lost the race must not clear the flag of
        // the newer one that is still in flight.
        if (!controller?.signal.aborted) searching.value = false;
    }
}

// ── Open / close lifecycle ──────────────────────────────────────────────────
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        results.value = { suites: [], tests: [], runs: [], conversations: [], skills: [] };
        searching.value = false;
        selectedIndex.value = 0;
        nextTick(() => inputEl.value?.focus());
    } else {
        clearTimeout(debounceTimer);
        controller?.abort();
    }
});

// The palette is mounted once in AppLayout; nothing to tear down beyond
// the pending request handled by the open watcher.
onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    controller?.abort();
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50">
            <!-- Backdrop: mousedown guard so a drag that starts inside the
                 panel and ends on the backdrop does not close the palette. -->
            <div
                class="absolute inset-0 bg-[var(--md-sys-color-scrim)]/40"
                @mousedown.self="closeCommandPalette"
            />

            <div
                class="relative mx-auto mt-[8vh] w-[calc(100%-2rem)] max-w-xl rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container)] shadow-elevation-3 overflow-hidden"
                role="dialog"
                aria-modal="true"
                :aria-label="t('commandPalette.title')"
            >
                <!-- Search input -->
                <div class="flex items-center gap-3 px-4 py-3 border-b border-[var(--md-sys-color-outline-variant)]">
                    <Search :size="18" class="flex-shrink-0 text-[var(--md-sys-color-on-surface-variant)]" />
                    <input
                        ref="inputEl"
                        v-model="query"
                        type="text"
                        class="flex-1 bg-transparent outline-none md-body-large text-[var(--md-sys-color-on-surface)] placeholder:text-[var(--md-sys-color-on-surface-variant)]"
                        :placeholder="t('commandPalette.placeholder')"
                        @keydown="onKeydown"
                    >
                    <span
                        v-if="searching"
                        class="w-4 h-4 rounded-full border-2 border-[var(--md-sys-color-primary)] border-t-transparent animate-spin flex-shrink-0"
                    />
                    <kbd class="md-label-small hidden sm:inline-flex items-center px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container-highest)] text-[var(--md-sys-color-on-surface-variant)] flex-shrink-0">esc</kbd>
                </div>

                <!-- Results -->
                <div ref="listContainer" class="max-h-[60vh] overflow-y-auto slim-scrollbar py-2">
                    <div v-if="hasResults">
                        <div v-for="group in groups" :key="group.key">
                            <p class="md-label-small uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] px-4 pt-2 pb-1">
                                {{ group.label }}
                            </p>
                            <button
                                v-for="item in group.items"
                                :key="item.id"
                                :data-index="flatItems.indexOf(item)"
                                type="button"
                                class="w-full flex items-center gap-3 px-4 py-2 text-left transition-colors"
                                :class="flatItems[selectedIndex] === item
                                    ? 'bg-[var(--md-sys-color-surface-container-high)]'
                                    : 'hover:bg-[var(--md-sys-color-surface-container-high)]'"
                                @mouseenter="selectedIndex = flatItems.indexOf(item)"
                                @click="select(item)"
                            >
                                <component
                                    :is="item.icon"
                                    :size="16"
                                    class="flex-shrink-0"
                                    :style="{ color: item.accent }"
                                />
                                <span class="flex-1 min-w-0">
                                    <span class="block md-body-medium text-[var(--md-sys-color-on-surface)] truncate">{{ item.label }}</span>
                                    <span v-if="item.sublabel" class="block md-body-small text-[var(--md-sys-color-on-surface-variant)] truncate">{{ item.sublabel }}</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div v-else class="px-4 py-8 text-center">
                        <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                            <template v-if="query.trim().length >= 2 && !searching">
                                {{ t('commandPalette.noResultsFor', { query: query.trim() }) }}
                            </template>
                            <template v-else>
                                {{ t('commandPalette.noResults') }}
                            </template>
                        </p>
                    </div>
                </div>

                <!-- Footer hints -->
                <div class="flex items-center gap-4 px-4 py-2 border-t border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-low)]">
                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] inline-flex items-center gap-1">
                        <kbd class="px-1 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container-highest)]">↑↓</kbd>
                        {{ t('commandPalette.hintNavigate') }}
                    </span>
                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] inline-flex items-center gap-1">
                        <kbd class="px-1 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container-highest)]">↵</kbd>
                        {{ t('commandPalette.hintOpen') }}
                    </span>
                </div>
            </div>
        </div>
    </Teleport>
</template>
