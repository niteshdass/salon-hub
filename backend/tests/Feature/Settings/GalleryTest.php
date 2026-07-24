<?php

namespace Tests\Feature\Settings;

use App\Models\Gallery;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gallery images for the public site. Everyone in the salon can look;
 * owner and manager curate.
 */
class GalleryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function scaffold(string $slug = 'alpha'): array
    {
        $org = Organization::create([
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'email' => "owner@{$slug}.test",
            'subscription_plan' => 'free',
            'status' => 'active',
        ]);

        $roles = [];
        foreach (['owner', 'manager', 'staff'] as $role) {
            $user = User::create([
                'organization_id' => $org->id,
                'name' => ucfirst($role),
                'email' => "{$role}@{$slug}.test",
                'password' => 'secret1234',
                'role' => $role,
                'status' => 'active',
            ]);
            $roles["{$role}Token"] = $user->createToken('api')->plainTextToken;
        }

        return ['org' => $org] + $roles;
    }

    private function actingAsRole(array $s, string $role): static
    {
        // Sanctum memoizes the resolved user for the whole test app.
        $this->app['auth']->forgetGuards();

        return $this->withToken($s["{$role}Token"]);
    }

    public function test_a_manager_uploads_an_image(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $response = $this->actingAsRole($s, 'manager')->post('/api/gallery', [
            'image' => UploadedFile::fake()->image('cut.jpg', 800, 600),
            'title' => 'Balayage',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Balayage');
        $this->assertNotNull($response->json('data.image_url'));

        $image = Gallery::withoutGlobalScopes()->first();
        $this->assertSame($s['org']->id, $image->organization_id);
        Storage::disk('public')->assertExists($image->image);
    }

    public function test_new_images_land_at_the_end(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        foreach (['one', 'two'] as $title) {
            $this->actingAsRole($s, 'owner')->post('/api/gallery', [
                'image' => UploadedFile::fake()->image("{$title}.jpg"),
                'title' => $title,
            ])->assertCreated();
        }

        $response = $this->actingAsRole($s, 'owner')->getJson('/api/gallery');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.title', 'one');
        $response->assertJsonPath('data.1.title', 'two');
        $this->assertSame([0, 1], Gallery::withoutGlobalScopes()->orderBy('id')->pluck('sort_order')->all());
    }

    public function test_the_index_honours_sort_order(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        Gallery::create(['organization_id' => $s['org']->id, 'image' => 'a.jpg', 'title' => 'last', 'sort_order' => 9]);
        Gallery::create(['organization_id' => $s['org']->id, 'image' => 'b.jpg', 'title' => 'first', 'sort_order' => 1]);

        $response = $this->actingAsRole($s, 'staff')->getJson('/api/gallery');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'first');
        $response->assertJsonPath('data.1.title', 'last');
    }

    public function test_a_manager_retitles_and_reorders_an_image(): void
    {
        $s = $this->scaffold();
        $image = Gallery::create([
            'organization_id' => $s['org']->id,
            'image' => 'a.jpg',
            'title' => 'Old',
            'sort_order' => 3,
        ]);

        $response = $this->actingAsRole($s, 'manager')->putJson("/api/gallery/{$image->id}", [
            'title' => 'New',
            'sort_order' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.title', 'New');
        $response->assertJsonPath('data.sort_order', 0);
    }

    public function test_deleting_an_image_removes_the_file(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $created = $this->actingAsRole($s, 'owner')->post('/api/gallery', [
            'image' => UploadedFile::fake()->image('cut.jpg'),
        ])->assertCreated();

        $image = Gallery::withoutGlobalScopes()->firstOrFail();

        $this->actingAsRole($s, 'owner')
            ->deleteJson("/api/gallery/{$image->id}")
            ->assertNoContent();

        Storage::disk('public')->assertMissing($image->image);
        $this->assertSame(0, Gallery::withoutGlobalScopes()->count());
        $this->assertNotNull($created->json('data.id'));
    }

    public function test_staff_may_look_but_not_touch(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();
        $image = Gallery::create(['organization_id' => $s['org']->id, 'image' => 'a.jpg']);

        $this->actingAsRole($s, 'staff')->getJson('/api/gallery')->assertOk();

        $this->actingAsRole($s, 'staff')->post('/api/gallery', [
            'image' => UploadedFile::fake()->image('cut.jpg'),
        ])->assertForbidden();

        $this->actingAsRole($s, 'staff')
            ->putJson("/api/gallery/{$image->id}", ['title' => 'Mine now'])
            ->assertForbidden();

        $this->actingAsRole($s, 'staff')
            ->deleteJson("/api/gallery/{$image->id}")
            ->assertForbidden();
    }

    public function test_an_image_is_required_and_must_be_an_image(): void
    {
        Storage::fake('public');
        $s = $this->scaffold();

        $this->actingAsRole($s, 'owner')->post('/api/gallery', ['title' => 'No file'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');

        $this->actingAsRole($s, 'owner')->post('/api/gallery', [
            'image' => UploadedFile::fake()->create('prices.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('image');
    }

    public function test_another_tenants_images_are_invisible(): void
    {
        $alpha = $this->scaffold('alpha');
        $beta = $this->scaffold('beta');

        $theirs = Gallery::create(['organization_id' => $beta['org']->id, 'image' => 'b.jpg']);

        $this->actingAsRole($alpha, 'owner')->getJson('/api/gallery')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsRole($alpha, 'owner')->deleteJson("/api/gallery/{$theirs->id}")
            ->assertNotFound();

        $this->assertSame(1, Gallery::withoutGlobalScopes()->count());
    }
}
