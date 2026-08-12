import { ref, onMounted, watchEffect } from 'vue';

const theme = ref('dark');

export function useTheme() {
    function applyTheme(value) {
        if (value === 'dark') {
            document.documentElement.classList.add('dark');
            document.documentElement.classList.remove('light');
        } else {
            document.documentElement.classList.add('light');
            document.documentElement.classList.remove('dark');
        }
    }

    function toggleTheme() {
        theme.value = theme.value === 'dark' ? 'light' : 'dark';
        localStorage.setItem('sorify-theme', theme.value);
        applyTheme(theme.value);
    }

    onMounted(() => {
        const stored = localStorage.getItem('sorify-theme');
        const preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        theme.value = stored ?? preferred;
        applyTheme(theme.value);
    });

    return { theme, toggleTheme };
}
