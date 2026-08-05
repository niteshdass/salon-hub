<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SalonHub</title>
    {{-- The Vue build is copied to public/app by the deploy script. The
         manifest is read at request time so a redeploy needs no cache clear.
         Vite keys the manifest by the input file it built ("index.html"),
         not by the script the page references internally. --}}
    @php
        $manifestPath = public_path('app/.vite/manifest.json');
        $manifest = file_exists($manifestPath)
            ? json_decode(file_get_contents($manifestPath), true)
            : null;
        $entry = $manifest['index.html'] ?? null;

        if (! $entry) {
            // A missing manifest/entry means the deploy script never copied a
            // built frontend into public/app. Log it so the failure shows up
            // in monitoring instead of shipping a silently blank page.
            \Illuminate\Support\Facades\Log::warning(
                'SPA build not found: expected an "index.html" entry in '.$manifestPath
            );
        }
    @endphp
    @if ($entry)
        @foreach ($entry['css'] ?? [] as $css)
            <link rel="stylesheet" href="/app/{{ $css }}">
        @endforeach
        <script type="module" src="/app/{{ $entry['file'] }}"></script>
    @endif
</head>
<body>
    <div id="app"></div>
    @unless ($entry)
        <p style="font-family: sans-serif; padding: 2rem; color: #b91c1c;">
            The application build is missing. Run <code>npm run build</code> in
            <code>frontend/</code> and copy <code>dist/</code> to <code>backend/public/app</code>.
        </p>
    @endunless
</body>
</html>
