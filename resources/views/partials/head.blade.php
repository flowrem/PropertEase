<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
<link rel="icon" href="/icon-192.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Light only: drop a "dark" class left on <html> by a page loaded before dark mode was removed,
     since wire:navigate keeps the <html> element between pages. --}}
<script data-navigate-once>
    (() => {
        const light = () => document.documentElement.classList.remove('dark');
        light();
        document.addEventListener('livewire:navigated', light);
    })();
</script>
