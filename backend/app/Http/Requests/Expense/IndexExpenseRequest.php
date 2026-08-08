<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The filters on the expense log. Every one is optional, but an unparseable
 * date has to fail as a 422 here rather than as an InvalidFormatException
 * thrown out of the controller's `$request->date()` call.
 */
class IndexExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Expense::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'category' => ['nullable', Rule::enum(ExpenseCategory::class)],
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('organization_id', app(CurrentTenant::class)->id()),
            ],
        ];
    }
}
