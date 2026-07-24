<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class GalleryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            // The stored value is a disk path; the client needs a URL.
            'image_url' => $this->image ? Storage::disk('public')->url($this->image) : null,
            'created_at' => $this->created_at,
        ];
    }
}
