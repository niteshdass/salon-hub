<?php

namespace App\Http\Requests\Payroll;

use App\Models\PayrollRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class StorePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PayrollRun::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The current month is allowed and produces a partial run; a month
        // that has not started has no revenue to pay anyone from.
        $limit = Carbon::now(config('app.timezone'))->endOfMonth()->toDateString();

        return [
            'period_month' => ['required', 'date', "before_or_equal:{$limit}"],
        ];
    }

    /** Any day in the month resolves to that month. */
    public function periodMonth(): Carbon
    {
        return Carbon::parse($this->date('period_month'))->startOfMonth();
    }
}
