<?php

namespace App\Http\Requests\Report;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class ReportRequest extends FormRequest
{
    /** Reports are money-sensitive: owner and manager only. */
    public function authorize(): bool
    {
        return $this->user()?->isManagerOrOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * Reject ranges longer than a year — bounds the PHP-side aggregation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            ['from' => $from, 'to' => $to] = $this->range();
            if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
                $validator->errors()->add('to', 'The date range must be 366 days or fewer.');
            }
        });
    }

    /**
     * Resolved window, defaults applied: last 30 days when nothing is given.
     *
     * @return array{from: string, to: string}
     */
    public function range(): array
    {
        $to = $this->date('to') ?? Carbon::now(config('app.timezone'))->startOfDay();
        $from = $this->date('from') ?? $to->copy()->subDays(29);

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
