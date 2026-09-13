import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Page context for the AI agent.
 *
 * The drawer reads this when starting a new chat so the agent knows which
 * page the user is on. Pages can register richer context via setAgentContext
 * (e.g. the suite page provides the suite id/name, the bookmarks page lists
 * the bookmarked suites); without an override the drawer falls back to the
 * current route's component name + URL.
 */
const override = ref(null);

/**
 * Register page context — either a plain object or a factory function
 * (evaluated reactively, so paginated pages stay current).
 */
export function setAgentContext(context) {
    override.value = context;
}

export function clearAgentContext() {
    override.value = null;
}

export function useAgentContext() {
    const page = usePage();

    const defaultContext = computed(() => ({
        pageName: page.component,
        pageUrl: page.url,
        context: '',
    }));

    const agentContext = computed(() => {
        if (override.value) {
            const resolved = typeof override.value === 'function' ? override.value() : override.value;

            return { ...defaultContext.value, ...resolved };
        }

        return defaultContext.value;
    });

    return { agentContext, setAgentContext, clearAgentContext };
}
