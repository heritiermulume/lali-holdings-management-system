<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/png" href="/favicon.png?v=3" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=3" />
    <link rel="shortcut icon" href="/favicon.png?v=3" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#043711" />
    <title>LHMS — LALI Holdings Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    @foreach ($assets['css'] ?? [] as $stylesheet)
      <link rel="stylesheet" href="{{ $stylesheet }}" />
    @endforeach
  </head>
  <body>
    <div id="root"></div>
    @if ($useVite)
      <script type="module" src="{{ $devServer }}/@@vite/client"></script>
      <script type="module" src="{{ $devServer }}/src/main.tsx"></script>
    @elseif (! empty($assets['js']))
      <script type="module" src="{{ $assets['js'] }}"></script>
    @else
      <script>
        document.getElementById('root').innerHTML = '<div style="font-family:Plus Jakarta Sans,sans-serif;padding:48px;max-width:520px"><h1 style="color:#043711">LHMS</h1><p>Démarrez le frontend : <code>cd frontend && npm run dev</code> ou construisez-le : <code>npm run build</code>.</p></div>';
      </script>
    @endif
  </body>
</html>
