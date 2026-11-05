<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php($pageTitle = filled($title ?? null) ? $title : 'Nightshift')
<title>{{ str_contains($pageTitle, 'Nightshift') ? $pageTitle : $pageTitle.' · Nightshift' }}</title>

@include('partials.brand-icons')

@include('partials.theme')

@vite(['resources/css/app.css', 'resources/js/app.js'])

