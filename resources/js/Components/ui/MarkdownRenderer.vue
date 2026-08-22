<script setup>
import { ref, computed, onMounted, onUpdated, watch, nextTick } from 'vue';
import { Marked } from 'marked';
import DOMPurify from 'dompurify';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    content: { type: String, default: '' },
    // Visual density: "default" uses body-medium sizing for general prose;
    // "compact" uses body-small sizing for tighter contexts (e.g. inside cards).
    density: { type: String, default: 'default' },
    // When true, shows only the first `collapsedLines` lines of content with a
    // "Show more" button to reveal the rest.
    collapsible: { type: Boolean, default: false },
    collapsedLines: { type: Number, default: 10 },
});

// ── Tailwind class maps per density ──────────────────────────────────────────
// Applied directly on each element via the marked renderer so they are
// bulletproof against Tailwind Preflight's heading reset.
// Classes are complete literals so Tailwind's JIT scanner detects them.
const HEADING_CLASSES = {
    default: [
        'text-2xl',      // h1 — 1.5rem
        'text-xl',       // h2 — 1.25rem
        'text-lg',       // h3 — 1.125rem
        'text-base',     // h4 — 1rem
        'text-sm',       // h5 — 0.875rem
        'text-sm',       // h6 — 0.875rem
    ],
    compact: [
        'text-base',     // h1 — 1rem
        'text-[15px]',   // h2
        'text-sm',       // h3 — 0.875rem
        'text-xs',       // h4 — 0.75rem
        'text-xs',       // h5
        'text-xs',       // h6
    ],
};

function createMarked(density) {
    const sizes = HEADING_CLASSES[density] ?? HEADING_CLASSES.default;
    const renderer = {
        heading({ text, depth, tokens }) {
            const sizeCls = sizes[depth - 1] ?? 'text-base';
            const extra = depth === 6 ? ' uppercase tracking-wide' : '';
            const inner = this.parser.parseInline(tokens);
            return `<h${depth} class="font-semibold ${sizeCls} mt-4 mb-2 leading-tight${extra}">${inner}</h${depth}>`;
        },
        paragraph({ text, tokens }) {
            const inner = this.parser.parseInline(tokens);
            return `<p class="mb-3 last:mb-0">${inner}</p>`;
        },
        strong({ text, tokens }) {
            const inner = tokens ? this.parser.parseInline(tokens) : text;
            return `<strong class="font-semibold">${inner}</strong>`;
        },
        em({ text, tokens }) {
            const inner = tokens ? this.parser.parseInline(tokens) : text;
            return `<em class="italic">${inner}</em>`;
        },
        codespan({ text }) {
            return `<code class="font-mono text-sm bg-[var(--md-sys-color-surface-container-high)] px-1 py-0.5 rounded">${text}</code>`;
        },
        link({ href, text, tokens }) {
            const inner = tokens ? this.parser.parseInline(tokens) : text;
            return `<a class="text-[var(--md-sys-color-primary)] hover:underline" href="${href}" target="_blank" rel="noopener noreferrer">${inner}</a>`;
        },
        code({ text, lang }) {
            const langCls = lang ? ` language-${lang}` : '';
            return `<pre class="bg-[var(--md-sys-color-surface-container-high)] p-3 rounded-[var(--md-sys-shape-corner-small)] overflow-x-auto mb-3"><code class="font-mono text-sm bg-transparent p-0${langCls}">${text}</code></pre>`;
        },
        hr() {
            return '<hr class="border-0 border-t border-[var(--md-sys-color-outline-variant)] my-3" />';
        },
        image({ href, title, text }) {
            const titleAttr = title ? ` title="${title}"` : '';
            return `<img class="max-w-full rounded-[var(--md-sys-shape-corner-small)]" src="${href}" alt="${text}"${titleAttr} />`;
        },
    };
    const marked = new Marked();
    marked.use({ renderer });
    return marked;
}

const rendered = computed(() => {
    const md = createMarked(props.density);
    // DOMPurify sanitizes the rendered HTML — strips <script>, on* handlers,
    // javascript: URLs, and <style>/<style> attributes (we use Tailwind classes
    // instead). Class attributes are preserved so our Tailwind classes survive.
    return DOMPurify.sanitize(md.parse(props.content ?? '', { async: false }), {
        FORBID_TAGS: ['style'],
        FORBID_ATTR: ['style'],
    });
});

// ── Collapsible logic ────────────────────────────────────────────────────────
const expanded = ref(false);
const contentRef = ref(null);
const needsCollapse = ref(false);

const collapsedMaxHeight = computed(() => {
    const lineH = props.density === 'compact' ? 16 : 22;
    return props.collapsedLines * lineH;
});

function checkCollapse() {
    if (!props.collapsible || !contentRef.value) return;
    const el = contentRef.value;
    const prev = el.style.maxHeight;
    el.style.maxHeight = 'none';
    const fullHeight = el.scrollHeight;
    el.style.maxHeight = prev;
    needsCollapse.value = fullHeight > collapsedMaxHeight.value + 24;
}

onMounted(() => nextTick(checkCollapse));
onUpdated(() => nextTick(checkCollapse));

watch(() => props.content, () => {
    expanded.value = false;
    needsCollapse.value = false;
    nextTick(checkCollapse);
});

function toggleExpand() {
    expanded.value = !expanded.value;
}
</script>

<template>
    <div v-if="content">
        <div
            ref="contentRef"
            class="break-words text-[var(--md-sys-color-on-surface)] [&>*:first-child]:mt-0 [&>*:last-child]:mb-0
                   [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mb-3 [&_ul]:space-y-1
                   [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mb-3 [&_ol]:space-y-1
                   [&_blockquote]:border-l-2 [&_blockquote]:border-[var(--md-sys-color-outline)] [&_blockquote]:pl-3 [&_blockquote]:italic [&_blockquote]:text-[var(--md-sys-color-on-surface-variant)] [&_blockquote]:mb-3
                   [&_table]:w-full [&_table]:mb-3 [&_table]:border-collapse
                   [&_th]:font-semibold [&_th]:text-left [&_th]:px-2 [&_th]:py-1 [&_th]:border [&_th]:border-[var(--md-sys-color-outline-variant)] [&_th]:bg-[var(--md-sys-color-surface-container-low)]
                   [&_td]:px-2 [&_td]:py-1 [&_td]:border [&_td]:border-[var(--md-sys-color-outline-variant)]"
            :class="density === 'compact' ? 'md-body-small' : 'md-body-medium'"
            :style="collapsible && !expanded ? { maxHeight: collapsedMaxHeight + 'px', overflow: 'hidden' } : null"
            v-html="rendered"
        />
        <button
            v-if="collapsible && needsCollapse"
            type="button"
            @click="toggleExpand"
            class="mt-1.5 md-label-small text-[var(--md-sys-color-primary)] hover:underline"
        >
            {{ expanded ? t('common.showLess') : t('common.showMore') }}
        </button>
    </div>
</template>
