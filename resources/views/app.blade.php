<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'HubPorn') }}</title>

        <!-- SEO Meta Tags -->
        <meta name="description" content="{{ $page['props']['seo']['description'] ?? 'Os melhores vídeos e fotos em um só lugar.' }}">
        <meta name="keywords" content="{{ $page['props']['seo']['keywords'] ?? 'porn, videos, photos, adult content' }}">
        <meta name="robots" content="index, follow">
        <link rel="canonical" href="{{ url()->current() }}">
        
        <!-- Open Graph / Facebook -->
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:title" content="{{ $page['props']['seo']['title'] ?? config('app.name', 'HubPorn') }}">
        <meta property="og:description" content="{{ $page['props']['seo']['description'] ?? 'Os melhores vídeos e fotos em um só lugar.' }}">
        <meta property="og:image" content="{{ $page['props']['seo']['image'] ?? url('/assets/images/og-image.jpg') }}">

        <!-- Twitter -->
        <meta property="twitter:card" content="summary_large_image">
        <meta property="twitter:url" content="{{ url()->current() }}">
        <meta property="twitter:title" content="{{ $page['props']['seo']['title'] ?? config('app.name', 'HubPorn') }}">
        <meta property="twitter:description" content="{{ $page['props']['seo']['description'] ?? 'Os melhores vídeos e fotos em um só lugar.' }}">
        <meta property="twitter:image" content="{{ $page['props']['seo']['image'] ?? url('/assets/images/og-image.jpg') }}">

        <!-- Performance: Preconnect to external resources -->
        <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="preconnect" href="{{ env('R2_URL', 'https://cdn.hubporn.online') }}" crossorigin>
        <link rel="dns-prefetch" href="{{ env('R2_URL', 'https://cdn.hubporn.online') }}">
        <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
        <link rel="dns-prefetch" href="https://www.googletagmanager.com">
        <link rel="dns-prefetch" href="https://www.google-analytics.com">
        
        <!-- Fonts with display=swap for better LCP -->
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

        <!-- Icons -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></noscript>
        
        <!-- SweetAlert2 -->
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-JZ5BLCPS4F"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-JZ5BLCPS4F');
</script>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
