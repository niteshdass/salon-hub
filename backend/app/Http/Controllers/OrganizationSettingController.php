<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateOrganizationRequest;
use App\Http\Requests\Settings\UploadOrganizationImageRequest;
use App\Models\Organization;
use App\Models\Setting;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * The salon profile: the organization row (contact details, branding
 * files) and its settings row (theme, story, social links) presented as
 * one flat object, because they are one form to the owner.
 */
class OrganizationSettingController extends Controller
{
    public function __construct(protected CurrentTenant $tenant) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', Organization::class);

        return response()->json(['data' => $this->payload()]);
    }

    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $data = $request->validated();

        $organization = $this->organization();
        $organization->fill(array_intersect_key($data, array_flip([
            'name', 'email', 'phone', 'country', 'timezone', 'currency',
        ])));
        $organization->save();

        // The settings row is created lazily — an org registered before
        // this endpoint existed may not have one.
        $settings = Setting::firstOrNew(['organization_id' => $organization->id]);
        $settings->fill(array_intersect_key($data, array_flip([
            'theme_color', 'about', 'facebook', 'instagram', 'website',
        ])));
        $settings->save();

        return response()->json(['data' => $this->payload()]);
    }

    public function uploadLogo(UploadOrganizationImageRequest $request): JsonResponse
    {
        return $this->replaceImage($request, 'logo');
    }

    public function deleteLogo(): JsonResponse
    {
        $this->authorize('update', Organization::class);

        return $this->clearImage('logo');
    }

    public function uploadCover(UploadOrganizationImageRequest $request): JsonResponse
    {
        return $this->replaceImage($request, 'cover_image');
    }

    public function deleteCover(): JsonResponse
    {
        $this->authorize('update', Organization::class);

        return $this->clearImage('cover_image');
    }

    /**
     * Store the upload and drop whatever it replaces, so storage does not
     * accumulate orphans every time the owner tries a new logo.
     */
    protected function replaceImage(UploadOrganizationImageRequest $request, string $field): JsonResponse
    {
        $organization = $this->organization();
        $previous = $organization->{$field};

        $path = $request->file('image')->store("organizations/{$organization->id}", 'public');

        $organization->{$field} = $path;
        $organization->save();

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json(['data' => $this->payload()]);
    }

    protected function clearImage(string $field): JsonResponse
    {
        $organization = $this->organization();

        if ($organization->{$field}) {
            Storage::disk('public')->delete($organization->{$field});
            $organization->{$field} = null;
            $organization->save();
        }

        return response()->json(['data' => $this->payload()]);
    }

    protected function organization(): Organization
    {
        return $this->tenant->get();
    }

    /**
     * One flat object: the org's own columns plus its settings row, with
     * the stored image paths resolved to URLs.
     *
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $organization = $this->organization()->fresh();
        $settings = Setting::query()->where('organization_id', $organization->id)->first();
        $disk = Storage::disk('public');

        return [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'country' => $organization->country,
            'timezone' => $organization->timezone,
            'currency' => $organization->currency,
            'logo_url' => $organization->logo ? $disk->url($organization->logo) : null,
            'cover_image_url' => $organization->cover_image ? $disk->url($organization->cover_image) : null,

            // Defaults mirror the settings table, so the form looks the
            // same before and after the first save.
            'theme_color' => $settings?->theme_color ?? '#6366f1',
            'about' => $settings?->about,
            'facebook' => $settings?->facebook,
            'instagram' => $settings?->instagram,
            'website' => $settings?->website,
        ];
    }
}
