<?php

namespace App\Http\Requests\BranchClosure;

use App\Models\BranchClosure;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchClosureRequest extends FormRequest
{
    /**
     * Role-gated here so a front-desk account gets a 403 before validation can
     * turn it into a 422.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', BranchClosure::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A null branch closes the whole salon. A given branch must belong
            // to this tenant, so a foreign id is a 422 rather than leaking that
            // the branch exists elsewhere.
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')
                    ->where('organization_id', app(CurrentTenant::class)->id()),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
