<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseCategory;
use App\Http\Requests\Concerns\ValidatesCommonExpenseFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    use ValidatesCommonExpenseFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('expense'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'required', Rule::enum(ExpenseCategory::class)],
            'expense_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:99999999.99'],
            ...$this->commonExpenseRules(),
        ];
    }
}
