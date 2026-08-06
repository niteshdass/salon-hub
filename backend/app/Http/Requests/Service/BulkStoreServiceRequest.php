<?php

namespace App\Http\Requests\Service;

use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A whole starter menu in one call, from the onboarding wizard.
 *
 * Rules mirror StoreServiceRequest per row so a menu can never contain a
 * service the single-create endpoint would have refused; the difference is
 * only the shape and the fact that errors are keyed by row index, which is
 * what lets the wizard highlight the offending line rather than saying
 * "something is wrong" about a list of eight.
 */
class BulkStoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Service::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:255'],
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.duration' => ['required', 'integer', 'min:1'],
            'rows.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.price.required' => 'Add a price for every service you ticked.',
            'rows.min' => 'Pick at least one service.',
        ];
    }

    /**
     * Every rule on `rows.*` that falls back to Laravel's default message
     * (i.e. everything but the two custom strings above) otherwise renders
     * the raw attribute path verbatim, e.g. "The rows.1.price field must be
     * at least 0." Laravel collapses numeric indices to `*` when resolving
     * these, the same mechanism the custom messages above rely on.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rows.*.name' => 'service name',
            'rows.*.duration' => 'duration',
            'rows.*.price' => 'price',
        ];
    }
}
