import { createApp, h } from 'vue';
import { createInertiaApp, Link, Head } from '@inertiajs/vue3';
import { createI18n } from 'vue-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import en from './locales/en.json';
import ja from './locales/ja.json';
import '../css/app.css';

createInertiaApp({
    title: (title) => title ? `${title} — Sorify` : 'Sorify',
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        ),
    setup({ el, App, props, plugin }) {
        const i18n = createI18n({
            legacy: false,
            locale: props.initialPage.props.locale ?? 'en',
            fallbackLocale: 'en',
            messages: { en, ja },
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .component('Link', Link)
            .component('Head', Head)
            .mount(el);
    },
    progress: {
        color: '#bf0000',
    },
});
