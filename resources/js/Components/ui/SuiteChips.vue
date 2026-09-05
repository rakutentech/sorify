<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SettingBadge from './SettingBadge.vue';

// The chip row shown on every suite row (suites page, bookmarks page).
// Only active/enabled chips render inline, capped at `maxVisible`; the rest
// collapse into a "+N more" chip whose hover tooltip reveals the full set —
// both enabled and disabled — so the row stays compact without losing info.
const props = defineProps({
    suite: { type: Object, required: true },
    maxVisible: { type: Number, default: 5 },
});

const { t } = useI18n();

const allChips = computed(() => [
    { label: t('testSuites.badgeTeams'), active: !!props.suite.has_teams_webhook, successActive: true, kind: 'teams' },
    { label: t('testSuites.badgeGithub'), active: !!props.suite.has_github_integration, successActive: true, kind: 'github' },
    { label: t('testSuites.badgeHttp'), active: !!props.suite.has_http_integration, successActive: true, kind: 'http' },
    { label: t('testSuites.badgeScreenshots'), active: !!props.suite.take_screenshot, successActive: true, kind: 'screenshots' },
    { label: t('testSuites.badgeProxy'), active: !!(props.suite.proxy_rules_count || props.suite.playwright_proxy), successActive: true, kind: 'proxy' },
    { label: t('testSuites.badgeVariables'), active: !!((props.suite.variables_count ?? 0) > 0), successActive: true, kind: 'variables' },
    { label: t('testSuiteShow.cookiesCount', { count: props.suite.cookies_count ?? 0 }), active: !!((props.suite.cookies_count ?? 0) > 0), successActive: true, kind: 'cookies' },
    { label: t('testSuites.badgeSchedule'), active: !!(props.suite.schedule && props.suite.schedule.is_enabled), successActive: true, kind: 'schedule' },
]);

const activeChips = computed(() => allChips.value.filter(c => c.active));
const visibleChips = computed(() => activeChips.value.slice(0, props.maxVisible));
const overflowCount = computed(() => activeChips.value.length - visibleChips.value.length);
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <SettingBadge v-for="chip in visibleChips" :key="chip.kind" v-bind="chip" />

        <span v-if="overflowCount > 0" class="relative group/more">
            <span
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] md-label-small border text-[var(--md-sys-color-primary)] border-[var(--md-sys-color-primary)] cursor-default transition-colors group-hover/more:bg-[var(--md-sys-color-primary)] group-hover/more:text-[var(--md-sys-color-on-primary)]"
            >
                {{ t('testSuites.moreChips', { count: overflowCount }) }}
            </span>
            <span
                class="pointer-events-none absolute left-0 top-full z-30 mt-1.5 hidden w-max max-w-72 group-hover/more:flex flex-col gap-1.5 p-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline-variant)] shadow-lg"
            >
                <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">
                    {{ t('testSuites.moreChipsTooltip') }}
                </span>
                <span class="flex flex-wrap gap-1.5">
                    <SettingBadge v-for="chip in allChips" :key="chip.kind" v-bind="chip" />
                </span>
            </span>
        </span>
    </div>
</template>
