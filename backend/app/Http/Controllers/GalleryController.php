<?php

namespace App\Http\Controllers;

use App\Http\Requests\Gallery\StoreGalleryRequest;
use App\Http\Requests\Gallery\UpdateGalleryRequest;
use App\Http\Resources\GalleryResource;
use App\Models\Gallery;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Gallery images for the public site. Gallery is auto-scoped by
 * BelongsToOrganization, so route-model binding cannot reach another
 * tenant's image (a foreign id 404s).
 */
class GalleryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Gallery::class);

        return GalleryResource::collection(
            Gallery::query()->orderBy('sort_order')->orderBy('id')->get(),
        );
    }

    public function store(StoreGalleryRequest $request, CurrentTenant $tenant): JsonResponse
    {
        $path = $request->file('image')->store("organizations/{$tenant->id()}/gallery", 'public');

        $image = Gallery::create([
            'image' => $path,
            'title' => $request->validated('title'),
            // New images go to the end of the existing order.
            'sort_order' => (int) (Gallery::query()->max('sort_order') ?? -1) + 1,
        ]);

        return (new GalleryResource($image))->response()->setStatusCode(201);
    }

    public function update(UpdateGalleryRequest $request, Gallery $gallery): GalleryResource
    {
        $gallery->update($request->validated());

        return new GalleryResource($gallery->fresh());
    }

    public function destroy(Gallery $gallery): Response
    {
        $this->authorize('delete', $gallery);

        // Drop the file with the row — nothing else refers to it.
        Storage::disk('public')->delete($gallery->image);
        $gallery->delete();

        return response()->noContent();
    }
}
