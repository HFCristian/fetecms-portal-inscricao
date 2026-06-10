<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ config('app.name', 'XVI FETECMS') }}</title>

    {{-- CSRF token para o Sanctum SPA (axios lê o cookie XSRF-TOKEN automaticamente). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    {{-- Fontes do protótipo: Space Grotesk, Inter, Orbitron + ícones Material Symbols. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Space+Grotesk:wght@500;600;700&family=Orbitron:wght@600;700&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" />

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body class="antialiased">
    <div id="app"></div>
</body>
</html>
