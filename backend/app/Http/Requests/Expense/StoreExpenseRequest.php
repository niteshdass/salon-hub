<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseCategory;
use App\Http\Requests\Concerns\ValidatesCommonExpenseFields;
use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    use ValidatesCommonExpenseFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', Expense::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            ...$this->commonExpenseRules(),
        ];
    }
}
