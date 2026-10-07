<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;

class SpaController extends Controller
{
    public function __invoke(): View
    {
        $devServer = rtrim((string) env('FRONTEND_DEV_SERVER', 'http://127.0.0.1:5173'), '/');
        $assets = $this->builtAssets();
        // Prefer the Vite HMR server in local so frontend edits appear live
        // without rebuilding, even when a production build already exists.
        $useVite = $this->shouldUseVite($devServer);

        return view('lhms', [
            'useVite' => $useVite,
            'devServer' => $devServer,
            'assets' => $useVite ? ['js' => null, 'css' => []] : $assets,
        ]);
    }

    private function shouldUseVite(string $devServer): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        try {
            return Http::timeout(1)->get($devServer)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{js: ?string, css: list<string>}
     */
    private function builtAssets(): array
    {
        $paths = [
            public_path('build/.vite/manifest.json'),
            public_path('build/manifest.json'),
        ];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($path), true);
            $entry = $manifest['src/main.tsx'] ?? $manifest['index.html'] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            return [
                'js' => isset($entry['file']) ? '/build/'.$entry['file'] : null,
                'css' => array_map(
                    fn (string $file) => '/build/'.$file,
                    $entry['css'] ?? []
                ),
            ];
        }

        return ['js' => null, 'css' => []];
    }
}
