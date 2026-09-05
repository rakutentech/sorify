import {
    CircleCheck, CircleX, CircleDot, TriangleAlert, Clock, LoaderCircle,
    Hourglass, Ban, CirclePause, CircleDashed, Pencil,
    GitBranch, CalendarClock, Cable, Hand,
    MessagesSquare, Camera, Globe, Braces, Cookie, Webhook, Workflow, Send,
    Monitor, EyeOff, Timer, RotateCcw, History,
} from '@lucide/vue';

// Test-run / status -> icon component (+ whether it should spin).
// Color is applied by the consumer (inherit via currentColor inside a chip,
// or an explicit color class/var for a standalone icon).
export const STATUS_ICON = {
    passed:    { icon: CircleCheck,   spin: false },
    completed: { icon: CircleCheck,   spin: false },
    active:    { icon: CircleDot,     spin: false },
    failed:    { icon: CircleX,       spin: false },
    error:     { icon: TriangleAlert, spin: false },
    timeout:   { icon: Clock,         spin: false },
    draft:     { icon: Pencil,        spin: false },
    running:   { icon: LoaderCircle,  spin: true },
    pending:   { icon: Hourglass,     spin: false },
    cancelled: { icon: Ban,           spin: false },
    disabled:  { icon: CirclePause,   spin: false },
    skipped:   { icon: CircleDashed,  spin: false },
    never_ran: { icon: CircleDashed,  spin: false },
};

// Semantic accent color (CSS var) for a status, for standalone colored icons.
export const STATUS_COLOR = {
    passed:    'var(--md-ext-color-success)',
    completed: 'var(--md-ext-color-success)',
    active:    'var(--md-ext-color-success)',
    failed:    'var(--md-sys-color-error)',
    error:     'var(--md-ext-color-warning)',
    timeout:   'var(--md-ext-color-warning)',
    draft:     'var(--md-ext-color-warning)',
    running:   'var(--md-sys-color-primary)',
    pending:   'var(--md-sys-color-primary)',
    cancelled: 'var(--md-sys-color-on-surface-variant)',
    disabled:  'var(--md-sys-color-on-surface-variant)',
    skipped:   'var(--md-sys-color-on-surface-variant)',
    never_ran: 'var(--md-sys-color-on-surface-variant)',
};

// Suite setting "kind" -> { icon, color }. `color` is the accent shown when
// active; inactive dims to on-surface-variant. Lets each SettingBadge carry
// a distinct, colorful icon instead of a generic checkmark.
export const SETTING_KIND = {
    teams:       { icon: MessagesSquare, color: 'var(--md-sys-color-primary)' },
    webhook:     { icon: Webhook,         color: 'var(--md-sys-color-tertiary)' },
    github:      { icon: Workflow,        color: 'var(--md-sys-color-on-surface)' },
    http:        { icon: Send,           color: 'var(--md-sys-color-tertiary)' },
    screenshots: { icon: Camera,          color: 'var(--md-sys-color-tertiary)' },
    proxy:       { icon: Globe,           color: 'var(--md-sys-color-secondary)' },
    variables:   { icon: Braces,          color: 'var(--md-ext-color-warning)' },
    cookies:     { icon: Cookie,          color: 'var(--md-ext-color-warning)' },
    schedule:    { icon: CalendarClock,   color: 'var(--md-sys-color-primary)' },
    // run-settings badges
    browser:     { icon: Monitor,         color: 'var(--md-sys-color-secondary)' },
    headless:    { icon: EyeOff,          color: 'var(--md-sys-color-on-surface-variant)' },
    timeout:     { icon: Timer,           color: 'var(--md-ext-color-warning)' },
    retries:     { icon: RotateCcw,       color: 'var(--md-sys-color-primary)' },
    keepRuns:    { icon: History,         color: 'var(--md-sys-color-tertiary)' },
};

// Run trigger source -> icon component.
export const SOURCE_ICON = {
    ci: GitBranch,
    schedule: CalendarClock,
    mcp: Cable,
    manual: Hand,
};
