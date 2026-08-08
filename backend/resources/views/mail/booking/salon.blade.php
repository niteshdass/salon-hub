@php
    use Illuminate\Support\Carbon;
    $start = Carbon::parse($appointment->start_time)->format('g:i A');
    $end = Carbon::parse($appointment->end_time)->format('g:i A');
    $date = $appointment->booking_date->format('l, F j, Y');
@endphp
@component('mail::message')
# New booking received

A new appointment has been booked. Details:

@component('mail::table')
|                 |                                          |
| --------------- | ---------------------------------------- |
| **Customer**    | {{ $appointment->customer->name }}       |
| **Phone**       | {{ $appointment->customer->phone ?? '—' }} |
| **Email**       | {{ $appointment->customer->email ?? '—' }} |
| **Services**    | {{ $appointment->lines->pluck('name')->join(', ') }} |
| **Professional**| {{ $appointment->staff->name }}          |
| **When**        | {{ $date }} · {{ $start }}–{{ $end }}    |
| **Location**    | {{ $appointment->branch->name }}         |
| **Status**      | {{ ucfirst($appointment->status->value) }} |
@endcomponent

@component('mail::button', ['url' => config('app.url')])
Open dashboard
@endcomponent
@endcomponent
