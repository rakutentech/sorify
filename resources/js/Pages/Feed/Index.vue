<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Autocomplete, Button, ScreenshotLightbox } from '@/Components/ui';
import ActivityCard from '@/Components/feed/ActivityCard.vue';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';
import { formatRelativeTime } from '@/utils/date';
import {
    Rss, FilterX, ArrowUp, LoaderCircle, Activity, FolderOpen,
    FileCode, Users, SlidersHorizontal, ChevronDown,
} from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    activities: {
        type: Object,
        default: () => ({ data: [], current_page: 1, last_page: 1 }),
    },
    filters: {
        type: Object,
        default: () => ({}),
    },
    filterOptions: {
        type: Object,
        default: () => ({ types: [], suites: [], users: [] }),
    },
});

const lightbox = useScreenshotLightbox();

// ---------------------------------------------------------------- filters
const selectedTypes = ref([...(props.filters.type ?? [])]);
const suiteId = ref(props.filters.suite_id ?? '');
const actorId = ref(props.filters.actor_id ?? '');
const fromDate = ref(props.filters.from ?? '');
const toDate = ref(props.filters.to ?? '');

const hasActiveFilters = computed(() =>
    selectedTypes.value.length > 0
    || suiteId.value !== ''
    || actorId.value !== ''
    || fromDate.value !== ''
    || toDate.value !== '');

const suiteOptions = computed(() =>
    (props.filterOptions.suites ?? []).map((s) => ({ id: s.id, name: s.name, email: '' })));
const userOptions = computed(() =>
    (props.filterOptions.users ?? []).map((u) => ({ id: u.id, name: u.name, email: u.email, avatar_url: u.avatar_url })));

// Activity type → category key. Single source of truth for the sidebar's
// filter chip groups and the feed's grouped sections.
const CATEGORY_GROUPS = [
    { key: 'runs', types: ['run_triggered', 'run_completed', 'run_cancelled'] },
    { key: 'suites', types: ['suite_created', 'suite_updated', 'suite_duplicated'] },
    { key: 'tests', types: ['test_created', 'test_updated', 'test_code_updated', 'test_deleted', 'test_status_changed'] },
    { key: 'people', types: ['suite_members_changed', 'user_registered', 'user_created'] },
    { key: 'settings', types: ['schedule_updated', 'variables_updated', 'cookies_updated', 'integration_updated', 'email_recipients_updated'] },
];

const TYPE_TO_CATEGORY = Object.fromEntries(
    CATEGORY_GROUPS.flatMap((group) => group.types.map((type) => [type, group.key])),
);

// Visual identity per category: header icon + accent (tinted icon chip,
// hairline gradients — accents mirror the ActivityCard TYPE_META palette).
const CATEGORY_META = {
    runs:     { icon: Activity,          accent: 'var(--md-sys-color-primary)' },
    suites:   { icon: FolderOpen,        accent: 'var(--md-sys-color-tertiary)' },
    tests:    { icon: FileCode,          accent: 'var(--md-sys-color-secondary)' },
    people:   { icon: Users,             accent: 'var(--md-ext-color-warning)' },
    settings: { icon: SlidersHorizontal, accent: 'var(--md-ext-color-success)' },
};

// Sidebar chips, filtered down to the types the server actually sent so
// stale groups never render empty headers.
const typeGroups = computed(() => CATEGORY_GROUPS
    .map((group) => ({ ...group, types: group.types.filter((type) => props.filterOptions.types.includes(type)) }))
    .filter((group) => group.types.length > 0));

function filterParams() {
    const params = {};
    if (selectedTypes.value.length) params.type = selectedTypes.value;
    if (suiteId.value !== '') params.suite_id = suiteId.value;
    if (actorId.value !== '') params.actor_id = actorId.value;
    if (fromDate.value) params.from = fromDate.value;
    if (toDate.value) params.to = toDate.value;
    return params;
}

function applyFilters() {
    showNewPill.value = false;
    router.get('/sorify/feed', filterParams(), { preserveState: true, replace: true });
}

function clearFilters() {
    selectedTypes.value = [];
    suiteId.value = '';
    actorId.value = '';
    fromDate.value = '';
    toDate.value = '';
    applyFilters();
}

function toggleType(type) {
    const index = selectedTypes.value.indexOf(type);
    if (index === -1) selectedTypes.value.push(type);
    else selectedTypes.value.splice(index, 1);
    applyFilters();
}

// ------------------------------------------------------- items + pagination
const items = ref([...(props.activities.data ?? [])]);
const page = ref(props.activities.current_page ?? 1);
const lastPage = ref(props.activities.last_page ?? 1);
const loadingMore = ref(false);

// Sections of the feed, one per non-empty activity category, ordered by
// whichever category holds the newest activity. items is newest-first, so
// a group's first item is also its latest — no extra max() pass needed.
// Each section carries its CATEGORY_META (icon, accent) for the header.
const groupedItems = computed(() => CATEGORY_GROUPS
    .map(({ key }) => {
        const groupItems = items.value.filter((item) => TYPE_TO_CATEGORY[item.type] === key);
        return { key, ...CATEGORY_META[key], items: groupItems, latest: groupItems[0] ?? null };
    })
    .filter((group) => group.items.length > 0)
    .sort((a, b) => (b.latest?.id ?? 0) - (a.latest?.id ?? 0)));

watch(() => props.activities, (fresh) => {
    items.value = fresh.data ?? [];
    page.value = fresh.current_page ?? 1;
    lastPage.value = fresh.last_page ?? 1;
    showNewPill.value = false;
    maybeStartPolling();
});

async function loadMore() {
    if (loadingMore.value || page.value >= lastPage.value) return;
    loadingMore.value = true;
    try {
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(filterParams())) {
            if (Array.isArray(value)) value.forEach((v) => params.append(key, v));
            else params.set(key, value);
        }
        params.set('page', String(page.value + 1));

        const response = await fetch(`/sorify/feed?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) return;

        const data = await response.json();
        const known = new Set(items.value.map((item) => item.id));
        items.value.push(...(data.data ?? []).filter((item) => !known.has(item.id)));
        page.value = data.current_page ?? page.value + 1;
        lastPage.value = data.last_page ?? lastPage.value;
    } catch {
        // network hiccup — tapping "Load more" again will retry
    } finally {
        loadingMore.value = false;
    }
}

onMounted(() => {
    maybeStartPolling();
});

onBeforeUnmount(() => {
    stopPolling();
});

// ------------------------------------------------------------ live polling
const liveRuns = ref(new Map());
const showNewPill = ref(false);
let pollTimer = null;
let trackedRunIds = new Set();

const latestKnownId = computed(() => items.value[0]?.id ?? 0);
const isOnFirstPageOnly = computed(() => page.value === 1);

function maybeStartPolling() {
    const hasActiveRun = items.value.some((item) => {
        if (item.type !== 'run_triggered' || !item.subject) return false;
        const status = liveRuns.value.get(item.subject.id)?.status ?? item.subject.status;
        return ['pending', 'running'].includes(status);
    });
    if (hasActiveRun) startPolling();
}

function startPolling() {
    if (pollTimer !== null) return;
    pollTimer = setInterval(pollOnce, 10000);
    pollOnce();
}

function stopPolling() {
    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function pollOnce() {
    try {
        const response = await fetch('/sorify/feed/poll', { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            stopPolling();
            return;
        }

        const data = await response.json();
        const active = new Map((data.active_runs ?? []).map((r) => [r.id, r]));
        const finished = [...trackedRunIds].filter((id) => !active.has(id));

        liveRuns.value = active;
        trackedRunIds = new Set(active.keys());

        if ((data.latest_activity_id ?? 0) > latestKnownId.value) {
            if (finished.length > 0 && isOnFirstPageOnly.value) {
                // A visible run finished — its result card is now at the top.
                router.reload({ only: ['activities'], preserveState: true, preserveScroll: true });
            } else {
                showNewPill.value = true;
            }
        }

        if (active.size === 0) stopPolling();
    } catch {
        stopPolling();
    }
}

function liveRunFor(activity) {
    return activity.subject ? liveRuns.value.get(activity.subject.id) ?? null : null;
}

function showNewActivities() {
    router.reload({ preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('feed.title')" />

        <div class="mb-8 flex items-center gap-3">
            <span class="w-11 h-11 rounded-[var(--md-sys-shape-corner-medium)] flex items-center justify-center flex-shrink-0 bg-[color-mix(in_srgb,var(--md-ext-color-success)_14%,transparent)]">
                <Rss :size="22" :style="{ color: 'var(--md-ext-color-success)' }" />
            </span>
            <div class="min-w-0">
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] truncate">
                    {{ t('feed.title') }}
                </h1>
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-0.5">{{ t('feed.subtitle') }}</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 items-start lg:grid-cols-[280px_minmax(0,1fr)]">
            <!-- Filter sidebar -->
            <aside class="min-w-0 lg:sticky lg:top-6">
                <div class="rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container-low)] p-4 space-y-4">
                    <!-- Activity type chips, grouped by category -->
                    <div
                        v-for="group in typeGroups"
                        :key="group.key"
                        :class="group !== typeGroups[0] ? 'pt-3 border-t border-[var(--md-sys-color-outline-variant)]' : ''"
                    >
                        <p class="mb-1.5 md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">
                            {{ t(`feed.filterGroups.${group.key}`) }}
                        </p>
                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                v-for="type in group.types"
                                :key="type"
                                type="button"
                                @click="toggleType(type)"
                                class="relative group w-full px-2 py-1 rounded-[var(--md-sys-shape-corner-full)] md-label-small transition-colors border text-center"
                                :class="selectedTypes.includes(type)
                                    ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] border-transparent'
                                    : 'text-[var(--md-sys-color-on-surface-variant)] border-[var(--md-sys-color-outline-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]'"
                            >
                                <!-- Inner span carries the truncation so the
                                     button's overflow stays visible for the
                                     hover tooltip anchored to it. -->
                                <span class="block truncate">{{ t(`feed.types.${type}`) }}</span>
                                <div class="pointer-events-none absolute left-1/2 bottom-full -translate-x-1/2 mb-2 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                                    <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                                        {{ t(`feed.types.${type}`) }}
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Suite / user / date range -->
                    <div class="space-y-3">
                        <Autocomplete
                            v-model="suiteId"
                            :options="suiteOptions"
                            :label="t('feed.filters.suite')"
                            :placeholder="t('feed.filters.allSuites')"
                            value-key="id"
                            :emit-on-input="false"
                            @update:model-value="applyFilters"
                        />
                        <Autocomplete
                            v-model="actorId"
                            :options="userOptions"
                            :label="t('feed.filters.actor')"
                            :placeholder="t('feed.filters.allUsers')"
                            value-key="id"
                            :emit-on-input="false"
                            @update:model-value="applyFilters"
                        />
                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5" for="feed-from">{{ t('feed.filters.dateRange') }}</label>
                            <div class="space-y-1.5">
                                <input
                                    id="feed-from"
                                    v-model="fromDate"
                                    type="date"
                                    class="w-full px-2.5 py-2 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                    :aria-label="t('feed.filters.from')"
                                />
                                <div class="md-label-small text-[var(--md-sys-color-on-surface-variant)] text-center">–</div>
                                <input
                                    v-model="toDate"
                                    type="date"
                                    class="w-full px-2.5 py-2 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                    :aria-label="t('feed.filters.to')"
                                    @change="applyFilters"
                                />
                            </div>
                        </div>
                    </div>

                    <div v-if="hasActiveFilters" class="flex justify-end">
                        <Button variant="text" @click="clearFilters">
                            <FilterX :size="14" />
                            {{ t('feed.filters.clear') }}
                        </Button>
                    </div>
                </div>
            </aside>

            <!-- Feed -->
            <div class="min-w-0">
                <!-- New activity pill -->
                <button
                    v-if="showNewPill"
                    type="button"
                    class="w-full mb-4 flex items-center justify-center gap-2 py-2 rounded-[var(--md-sys-shape-corner-full)] md-label-large bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)] shadow-elevation-1 hover:shadow-elevation-2 hover:opacity-95 active:scale-[0.99] transition-all"
                    @click="showNewActivities"
                >
                    <ArrowUp :size="14" />
                    {{ t('feed.newActivity') }}
                </button>

                <!-- Empty state -->
                <div v-if="!items.length" class="rounded-[var(--md-sys-shape-corner-large)] border border-dashed border-[var(--md-sys-color-outline-variant)] px-5 py-16 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                    <span class="w-14 h-14 rounded-[var(--md-sys-shape-corner-full)] mx-auto mb-4 flex items-center justify-center bg-[var(--md-sys-color-surface-container-high)]">
                        <Rss :size="24" class="opacity-50" />
                    </span>
                    {{ t('feed.noneYet') }}
                </div>

                <!-- Grouped sections, newest activity first -->
                <div v-else class="space-y-10">
                    <section v-for="group in groupedItems" :key="group.key">
                        <!-- Sticky glassy header: icon chip, category, count,
                             fading hairline, latest activity time -->
                        <div class="sticky top-0 z-10 -mx-2 px-2 py-2.5 mb-4 bg-[color-mix(in_srgb,var(--md-sys-color-surface)_85%,transparent)] backdrop-blur-md">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span
                                    class="w-8 h-8 rounded-[var(--md-sys-shape-corner-small)] flex items-center justify-center flex-shrink-0"
                                    :style="{
                                        backgroundColor: `color-mix(in srgb, ${group.accent} 14%, transparent)`,
                                        color: group.accent,
                                    }"
                                >
                                    <component :is="group.icon" :size="16" />
                                </span>
                                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] truncate">
                                    {{ t(`feed.filterGroups.${group.key}`) }}
                                </h2>
                                <span class="flex-shrink-0 px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]">
                                    {{ group.items.length }}
                                </span>
                                <div class="flex-1 min-w-4 h-px bg-gradient-to-r from-[var(--md-sys-color-outline-variant)] to-transparent" />
                                <span
                                    class="md-label-small text-[var(--md-sys-color-on-surface-variant)] whitespace-nowrap flex-shrink-0 flex items-center gap-1.5"
                                    :title="t('feed.latestActivity')"
                                >
                                    <span
                                        class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                                        :style="{ backgroundColor: group.accent }"
                                    />
                                    {{ t('feed.latestActivity') }} · {{ formatRelativeTime(group.latest.created_at) }}
                                </span>
                            </div>
                        </div>

                        <!-- Cards, staggered entrance; new items animate in as
                             load-more merges them (keyed diff keeps the rest) -->
                        <div class="space-y-3">
                            <div
                                v-for="(activity, index) in group.items"
                                :key="activity.id"
                                class="animate-fade-slide-in"
                                :style="{ animationDelay: `${Math.min(index, 10) * 30}ms` }"
                            >
                                <ActivityCard
                                    :activity="activity"
                                    :live-run="liveRunFor(activity)"
                                    @open-lightbox="lightbox.open"
                                />
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Load more / end of feed -->
                <div v-if="items.length" class="pt-8 flex items-center justify-center gap-3">
                    <span v-if="loadingMore" class="inline-flex items-center gap-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)]">
                        <LoaderCircle :size="16" class="animate-spin" />
                        {{ t('feed.loadingMore') }}
                    </span>
                    <template v-else-if="page >= lastPage">
                        <div class="flex-1 h-px bg-[var(--md-sys-color-outline-variant)] opacity-40" />
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 whitespace-nowrap px-2">
                            {{ t('feed.endOfFeed') }}
                        </span>
                        <div class="flex-1 h-px bg-[var(--md-sys-color-outline-variant)] opacity-40" />
                    </template>
                    <Button v-else variant="tonal" @click="loadMore">
                        <ChevronDown :size="16" />
                        {{ t('feed.loadMore') }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Screenshot lightbox -->
        <ScreenshotLightbox
            :shots="lightbox.shots.value"
            :index="lightbox.index.value"
            @close="lightbox.close"
            @update:index="lightbox.setIndex"
        />
    </AppLayout>
</template>
