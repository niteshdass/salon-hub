@php
    use Illuminate\Support\Carbon;
    $start = Carbon::parse($appointment->start_time)->format('g:i A');
    $end = Carbon::parse($appointment->end_time)->format('g:i A');
    $date = $appointment->booking_date->format('l, F j, Y');
    $salon = $appointment->organization?->name ?? 'the salon';
@endphp
@component('mail::message')
@if($audience === 'salon')
# Booking cancelled

**{{ $appointment->customer->name }}** cancelled their appointment.
@else
# Your booking has been cancelled

Hi {{ $appointment->customer->name }}, your appointment at **{{ $salon }}** has been cancelled.
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
| **Was**         | {{ $date }} · {{ $start }}–{{ $end }}    |
| **Location**    | {{ $appointment->branch->name }}         |
@endcomponent

@if($audience === 'salon')
@component('mail::button', ['url' => config('app.url')])
Open dashboard
@endcomponent
@else
Changed your mind? You can book again any time.

{{ $salon }}
@endif
@endcomponent
