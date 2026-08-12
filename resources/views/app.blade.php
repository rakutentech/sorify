<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <!-- Prevent flash of wrong theme -->
    <script>
        (function() {
            var stored = localStorage.getItem('sorify-theme');
            var preferred = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            var theme = stored || preferred;
            document.documentElement.classList.remove('dark', 'light');
            document.documentElement.classList.add(theme);
        })();
    </script>
    @inertiaHead
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-base text-primary antialiased">
    @inertia
</body>
</html>
