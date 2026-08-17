import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { router } from '@inertiajs/vue3';

const SUPPORTED_LOCALES = [
    { value: 'en', label: 'English' },
    { value: 'ja', label: '日本語' },
];

const locale = ref(null);

export function useLocale() {
    const { locale: i18nLocale } = useI18n();

    if (locale.value === null) {
        locale.value = i18nLocale.value;
    }

    function setLocale(value) {
        locale.value = value;
        i18nLocale.value = value;
        localStorage.setItem('sorify-locale', value);
        document.documentElement.setAttribute('lang', value);

        router.patch('/sorify/profile/locale', { locale: value }, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    return { locale, setLocale, locales: SUPPORTED_LOCALES };
}
