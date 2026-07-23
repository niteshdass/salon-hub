@php
    use Illuminate\Support\Carbon;
    $start = Carbon::parse($appointment->start_time)->format('g:i A');
    $end = Carbon::parse($appointment->end_time)->format('g:i A');
    $date = $appointment->booking_date->format('l, F j, Y');
    $salon = $appointment->organization?->name ?? 'the salon';
@endphp
@component('mail::message')
@if($audience === 'salon')
# Booking rescheduled

**{{ $appointment->customer->name }}** moved their appointment to a new time.
@else
# Your booking has been rescheduled

Hi {{ $appointment->customer->name }}, your appointment at **{{ $salon }}** is now:
@endif

@component('mail::table')
|                 |                                          |
| --------------- | ---------------------------------------- |
@if($audience === 'salon')
| **Customer**    | {{ $appointment->customer->name }}       |
| **Phone**       | {{ $appointment->customer->phone ?? '—' }} |
@endif
| **Service**     | {{ $appointment->service->name }}        |
| **Professional**| {{ $appointment->staff->name }}          |
| **New time**    | {{ $date }} · {{ $start }}–{{ $end }}    |
| **Location**    | {{ $appointment->branch->name }}         |
@endcomponent

@if($audience === 'salon')
@component('mail::button', ['url' => config('app.url')])
Open dashboard
@endcomponent
@else
Need to change it again? Use your manage link or call the salon.

See you soon,<br>
{{ $salon }}
@endif
@endcomponent
