<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>{{ isset($title) ? $title . ' – ' . config('app.name') : config('app.name') }}</title>
@if (filled($metaDescription ?? null))
{{-- The slot content is already escaped once, so decode it before it is escaped again here --}}
<meta name="description" content="{{ trim(strip_tags(html_entity_decode($metaDescription, ENT_QUOTES))) }}">
@endif
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#231f20">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Membrum">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
<link rel="stylesheet" href="https://unpkg.com/trix@2.0.8/dist/trix.css">
<script src="https://unpkg.com/trix@2.0.8/dist/trix.umd.min.js" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.Trix) return;
    Object.assign(Trix.config.lang, {
        attachFiles:        'Bifoga filer',
        bold:               'Fet',
        bullets:            'Punktlista',
        captionPlaceholder: 'Lägg till bildtext…',
        code:               'Kod',
        heading1:           'Rubrik',
        indent:             'Öka nivå',
        italic:             'Kursiv',
        link:               'Länk',
        numbers:            'Nummerlista',
        outdent:            'Minska nivå',
        quote:              'Citat',
        redo:               'Gör om',
        remove:             'Ta bort',
        strike:             'Genomstruken',
        underline:          'Understruken',
        undo:               'Ångra',
        unlink:             'Ta bort länk',
        urlPlaceholder:     'Ange en URL…',
    });
});
</script>
