<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Autocomplete, Button, ScreenshotLightbox } from '@/Components/ui';
import ActivityCard from '@/Components/feed/ActivityCard.vue';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';
import { Rss, FilterX, ArrowUp, LoaderCircle } from '@lucide/vue';

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
        // network hiccup — the next intersection retry will try again
    } finally {
        loadingMore.value = false;
    }
}

const sentinel = ref(null);
let observer = null;

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => { if (entries[0].isIntersecting) loadMore(); },
        { rootMargin: '600px 0px' },
    );
    if (sentinel.value) observer.observe(sentinel.value);
    maybeStartPolling();
});

onBeforeUnmount(() => {
    observer?.disconnect();
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

        <div class="mb-6">
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                <Rss :size="26" :style="{ color: 'var(--md-ext-color-success)' }" />
                {{ t('feed.title') }}
            </h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('feed.subtitle') }}</p>
        </div>

        <!-- Filter bar -->
        <div class="rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container-low)] p-4 mb-5 space-y-4">
            <!-- Activity type chips -->
            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    v-for="type in filterOptions.types"
                    :key="type"
                    type="button"
                    @click="toggleType(type)"
                    class="px-3 py-1 rounded-[var(--md-sys-shape-corner-full)] md-label-small transition-colors border"
                    :class="selectedTypes.includes(type)
                        ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] border-transparent'
                        : 'text-[var(--md-sys-color-on-surface-variant)] border-[var(--md-sys-color-outline-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]'"
                >
                    {{ t(`feed.types.${type}`) }}
                </button>
            </div>

            <!-- Suite / user / date range -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                <div class="lg:col-span-2">
                    <Autocomplete
                        v-model="suiteId"
                        :options="suiteOptions"
                        :label="t('feed.filters.suite')"
                        :placeholder="t('feed.filters.allSuites')"
                        value-key="id"
                        :emit-on-input="false"
                        @update:model-value="applyFilters"
                    />
                </div>
                <div class="lg:col-span-2">
                    <Autocomplete
                        v-model="actorId"
                        :options="userOptions"
                        :label="t('feed.filters.actor')"
                        :placeholder="t('feed.filters.allUsers')"
                        value-key="id"
                        :emit-on-input="false"
                        @update:model-value="applyFilters"
                    />
                </div>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5" for="feed-from">{{ t('feed.filters.dateRange') }}</label>
                        <div class="flex items-center gap-1.5">
                            <input
                                id="feed-from"
                                v-model="fromDate"
                                type="date"
                                class="w-full px-2.5 py-2 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                :aria-label="t('feed.filters.from')"
                            />
                            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">–</span>
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
            </div>

            <div v-if="hasActiveFilters" class="flex justify-end">
                <Button variant="text" @click="clearFilters">
                    <FilterX :size="14" />
                    {{ t('feed.filters.clear') }}
                </Button>
            </div>
        </div>

        <!-- New activity pill -->
        <button
            v-if="showNewPill"
            type="button"
            class="w-full mb-4 flex items-center justify-center gap-2 py-2 rounded-[var(--md-sys-shape-corner-full)] md-label-large bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)] hover:opacity-90 transition-opacity"
            @click="showNewActivities"
        >
            <ArrowUp :size="14" />
            {{ t('feed.newActivity') }}
        </button>

        <!-- Feed -->
        <div v-if="!items.length" class="rounded-[var(--md-sys-shape-corner-medium)] border border-dashed border-[var(--md-sys-color-outline-variant)] px-5 py-12 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
            <Rss :size="32" class="mx-auto mb-3 opacity-40" />
            {{ t('feed.noneYet') }}
        </div>

        <div v-else class="space-y-3">
            <ActivityCard
                v-for="activity in items"
                :key="activity.id"
                :activity="activity"
                :live-run="liveRunFor(activity)"
                @open-lightbox="lightbox.open"
            />
        </div>

        <!-- Infinite scroll sentinel -->
        <div v-if="items.length" ref="sentinel" class="py-6 flex items-center justify-center">
            <span v-if="loadingMore" class="inline-flex items-center gap-2 md-label-medium text-[var(--md-sys-color-on-surface-variant)]">
                <LoaderCircle :size="16" class="animate-spin" />
                {{ t('feed.loadingMore') }}
            </span>
            <span v-else-if="page >= lastPage" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-70">
                {{ t('feed.endOfFeed') }}
            </span>
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
