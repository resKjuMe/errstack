{{-- Dark Mode: Theme vor dem ersten Rendern anwenden, damit kein hell/dunkel-
     Flash entsteht. Die Wahl liegt in localStorage ('theme' = light|dark|system,
     Standard: system). Muss synchron im <head> laufen, vor dem Body. --}}
<script>
    (function () {
        try {
            var mode = localStorage.getItem('theme') || 'system';
            var dark = mode === 'dark' || (mode === 'system'
                && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) { /* localStorage evtl. nicht verfügbar */ }
    })();
</script>
