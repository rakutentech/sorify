<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, MarkdownRenderer, Pagination } from '@/Components/ui';
import { BookMarked, CircleCheck, Download, LoaderCircle, Search, UserCircle } from '@lucide/vue';
import { clearAgentContext, setAgentContext } from '@/composables/useAgentContext';

const { t } = useI18n();

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const props = defineProps({
    skills: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({ search: '' }),
    },
    skillLimit: {
        type: Number,
        default: 25,
    },
    limitReached: {
        type: Boolean,
        default: false,
    },
});

const search = ref(props.filters.search ?? '');
const copyingId = ref(null);
const copyResult = ref(null);

// Selected skill whose full content shows in the right-hand pane — the
// first skill of the current page by default. A ?skill={id} query param
// (the profile's "installed from" link) preselects and scrolls to that
// skill instead, when it is on the current page.
const focusId = computed(() => props.filters.skill ?? null);

function skillOnPage(id) {
    return props.skills.data.some((skill) => skill.id === id);
}

const selectedId = ref(
    focusId.value != null && skillOnPage(focusId.value)
        ? focusId.value
        : (props.skills.data[0]?.id ?? null),
);

watch(() => props.skills.data, (items) => {
    if (! items.some((skill) => skill.id === selectedId.value)) {
        selectedId.value = items[0]?.id ?? null;
    }
}, { immediate: true });

watch(focusId, (id) => {
    if (id != null && skillOnPage(id) && id !== selectedId.value) {
        selectedId.value = id;
        scrollSkillIntoView(id);
    }
});

onMounted(() => {
    if (focusId.value != null && skillOnPage(focusId.value)) {
        scrollSkillIntoView(focusId.value);
    }
});

function scrollSkillIntoView(id) {
    document.getElementById(`skill-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

const selectedSkill = computed(() =>
    props.skills.data.find((skill) => skill.id === selectedId.value) ?? null);

function selectSkill(skill) {
    selectedId.value = skill.id;
}

function reload() {
    router.get(
        '/sorify/skills/browse',
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

const debouncedSearch = debounce(() => reload(), 350);

watch(search, () => debouncedSearch());

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function copySkill(skill) {
    if (skill.installed || skill.owned) return;

    copyingId.value = skill.id;
    copyResult.value = null;

    try {
        const response = await fetch(`/sorify/skills/${skill.id}/copy`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });

        if (response.ok) {
            const body = await response.json();

            if (body.already_installed) {
                skill.installed = true;
                copyResult.value = { name: skill.name, already: true };
            } else {
                skill.installed = true;
                skill.copies_count += 1;
                copyResult.value = { name: skill.name };
            }
        } else {
            copyResult.value = { error: true };
        }
    } catch {
        copyResult.value = { error: true };
    } finally {
        copyingId.value = null;
    }
}

// The author of a skill doesn't install it — the button turns into a
// passive "Your skill" label. Everything stays disabled once the viewer's
// collection is full, with a tooltip explaining why.
function installLabel(skill) {
    if (skill.owned) return t('skills.ownSkill');
    if (skill.installed) return t('skills.installedButton');
    return t('skills.install');
}

function installTitle(skill) {
    if (skill.owned) return t('skills.ownSkillTitle');
    if (skill.installed) return t('skills.installedButtonTitle');
    if (props.limitReached) return t('skills.limitReachedTitle', { limit: props.skillLimit });
    return null;
}

// The Button component sets pointer-events: none on disabled buttons,
// which swallows the native title tooltip — so the tooltip lives on a
// wrapping span that still receives hover.
function installButtonBindings(skill) {
    return props.limitReached && !skill.installed && !skill.owned
        ? { title: t('skills.limitReachedTitle', { limit: props.skillLimit }) }
        : {};
}

// AI agent page context: the public skills visible on this page.
setAgentContext(() => ({
    context: JSON.stringify({
        page: 'skills_browse',
        public_skills: props.skills.data.map(skill => ({
            skill_id: skill.id,
            name: skill.name,
            description: skill.description ?? null,
            author: skill.author_name ?? null,
            copies_count: skill.copies_count,
        })),
    }, null, 2),
}));

onUnmounted(() => clearAgentContext());
</script>

<template>
    <AppLayout>
        <Head :title="t('skills.browseTitle')" />

        <div class="flex flex-col flex-1 min-h-0">

            <!-- Header -->
            <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                        <BookMarked :size="26" :style="{ color: 'var(--md-sys-color-primary)' }" />
                        {{ t('skills.browseTitle') }}
                    </h1>
                    <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('skills.browseSubtitle') }}</p>
                </div>
                <Button
                    variant="tonal"
                    size="sm"
                    href="/sorify/profile?section=skills"
                    :title="t('skills.publishTitle')"
                >
                    <template #leading><UserCircle :size="14" /></template>
                    {{ t('skills.publish') }}
                </Button>
            </div>

            <!-- Search -->
            <div class="mb-4">
                <label class="relative block max-w-md">
                    <Search :size="16" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" />
                    <input
                        v-model="search"
                        type="text"
                        class="w-full md-body-medium bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-full)] pl-10 pr-4 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                        :placeholder="t('skills.searchPlaceholder')"
                    >
                </label>
            </div>

            <p
                v-if="copyResult"
                class="md-body-small rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 mb-4"
                :class="copyResult.error
                    ? 'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]'
                    : 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]'"
            >
                {{ copyResult.error
                    ? t('skills.installFailed')
                    : copyResult.already
                        ? t('skills.alreadyInstalled', { name: copyResult.name })
                        : t('skills.installed', { name: copyResult.name }) }}
            </p>

            <!-- Skills grid -->
            <div v-if="skills.data.length === 0" class="flex flex-col items-center py-16 text-center">
                <BookMarked :size="32" class="text-[var(--md-sys-color-on-surface-variant)] mb-3" />
                <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('skills.browseEmpty') }}</p>
            </div>

            <!-- Master–detail: skill list on the left, the selected
                 skill's full content on the right (first skill selected
                 by default). Stacks on small screens. -->
            <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
                <!-- List column -->
                <div class="space-y-3">
                    <Card
                        v-for="skill in skills.data"
                        :key="skill.id"
                        :id="`skill-${skill.id}`"
                        padding="p-5"
                        variant="outlined"
                        class="cursor-pointer transition-colors"
                        :class="skill.id === selectedId
                            ? 'border-[var(--md-sys-color-primary)] bg-[color-mix(in_srgb,var(--md-sys-color-primary)_8%,var(--md-sys-color-surface))]'
                            : 'hover:bg-[var(--md-sys-color-surface-container-low)]'"
                        @click="selectSkill(skill)"
                    >
                        <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] truncate">{{ skill.name }}</h2>
                                    <span
                                        class="md-label-small inline-flex items-center gap-1 text-[var(--md-sys-color-on-surface-variant)] flex-shrink-0"
                                        :title="t('skills.installsCount', { count: skill.copies_count })"
                                    >
                                        <Download :size="11" />
                                        {{ skill.copies_count }}
                                    </span>
                                </div>
                                <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] flex items-center gap-1.5 mt-1">
                                    <UserCircle :size="13" class="flex-shrink-0" />
                                    {{ skill.author_name ?? t('skills.unknownAuthor') }}
                                </p>
                            </div>
                            <span
                                v-bind="installButtonBindings(skill)"
                                class="inline-flex"
                            >
                                <Button
                                    variant="tonal"
                                    size="sm"
                                    :disabled="copyingId === skill.id || skill.installed || skill.owned || limitReached"
                                    :title="installTitle(skill)"
                                    @click.stop="copySkill(skill)"
                                >
                                    <LoaderCircle v-if="copyingId === skill.id" :size="14" class="animate-spin" />
                                    <UserCircle v-else-if="skill.owned" :size="14" />
                                    <CircleCheck v-else-if="skill.installed" :size="14" />
                                    <Download v-else :size="14" />
                                    {{ installLabel(skill) }}
                                </Button>
                            </span>
                        </div>

                        <p v-if="skill.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-3">{{ skill.description }}</p>
                    </Card>
                </div>

                <!-- Content column: the selected skill, full markdown -->
                <Card v-if="selectedSkill" padding="p-0" class="lg:sticky lg:top-20 overflow-hidden">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                            <BookMarked :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />
                            {{ selectedSkill.name }}
                        </h2>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] flex items-center gap-1.5 mt-1">
                            <UserCircle :size="13" class="flex-shrink-0" />
                            {{ selectedSkill.author_name ?? t('skills.unknownAuthor') }}
                            <span class="opacity-60">·</span>
                            <Download :size="11" class="flex-shrink-0" :title="t('skills.installsCount', { count: selectedSkill.copies_count })" />
                            {{ selectedSkill.copies_count }}
                        </p>
                        <p v-if="selectedSkill.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-2">
                            {{ selectedSkill.description }}
                        </p>
                    </div>

                    <!-- Full content, uncapped — the page scrolls instead of an
                         inner scroll area, so nothing is hidden behind a
                         scroll thumb. -->
                    <div class="px-5 py-4 max-h-[calc(100vh-16rem)] overflow-y-auto slim-scrollbar">
                        <MarkdownRenderer :content="selectedSkill.content" density="compact" />
                    </div>
                </Card>
            </div>

            <Pagination
                v-if="skills.data.length > 0"
                :paginator="skills"
                :label="t('skills.pagination', { from: skills.from ?? 0, to: skills.to ?? 0, total: skills.total ?? 0 })"
                class="mt-4"
            />
        </div>
    </AppLayout>
</template>
