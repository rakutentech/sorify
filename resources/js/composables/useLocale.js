import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePage, router } from '@inertiajs/vue3';

const SUPPORTED_LOCALES = [
    { value: 'en', label: 'English' },
    { value: 'ja', label: '日本語' },
    { value: 'ms', label: 'Bahasa Melayu' },
    { value: 'zh-CN', label: '简体中文' },
];

const COOKIE_NAME = 'sorify-locale';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 365; // 1 year

const locale = ref(null);

function writeCookie(value) {
    document.cookie = `${COOKIE_NAME}=${encodeURIComponent(value)}; max-age=${COOKIE_MAX_AGE}; path=/; samesite=lax`;
}

export function useLocale() {
    const { locale: i18nLocale } = useI18n();
    const page = usePage();

    if (locale.value === null) {
        locale.value = i18nLocale.value;
    }

    function setLocale(value) {
        locale.value = value;
        i18nLocale.value = value;
        localStorage.setItem(COOKIE_NAME, value);
        writeCookie(value);
        document.documentElement.setAttribute('lang', value);

        // Persist server-side only for authenticated users; guests rely on the cookie.
        if (page.props.auth?.user) {
            router.patch('/sorify/profile/locale', { locale: value }, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    }

    return { locale, setLocale, locales: SUPPORTED_LOCALES };
}
