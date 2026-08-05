<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_deep_link_returns_the_spa_shell(): void
    {
        $this->get('/salon/beauty-queen')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_unknown_api_route_still_returns_json_404(): void
    {
        $this->getJson('/api/does-not-exist')->assertStatus(404);
    }

    public function test_health_check_is_not_swallowed_by_the_fallback(): void
    {
        $this->get('/up')->assertOk();
    }

    /**
     * Asserting only `id="app"` (as the previous test does) would still pass
     * even if the manifest were never read — a permanently blank shell. This
     * proves the built script and stylesheet are actually wired in when a
     * deploy has copied a real Vite manifest into public/app.
     */
    public function test_deep_link_includes_the_built_script_and_stylesheet_when_a_manifest_exists(): void
    {
        $viteDir = public_path('app/.vite');
        File::ensureDirectoryExists($viteDir);
        File::put($viteDir.'/manifest.json', json_encode([
            'index.html' => [
                'file' => 'assets/index-TEST123.js',
                'isEntry' => true,
                'css' => ['assets/index-TEST456.css'],
            ],
        ]));

        try {
            $this->get('/salon/beauty-queen')
                ->assertOk()
                ->assertSee('<script type="module" src="/app/assets/index-TEST123.js"></script>', false)
                ->assertSee('<link rel="stylesheet" href="/app/assets/index-TEST456.css">', false);
        } finally {
            File::deleteDirectory(public_path('app'));
        }
    }
}
