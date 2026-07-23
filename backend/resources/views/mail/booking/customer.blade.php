@php
    use Illuminate\Support\Carbon;
    $start = Carbon::parse($appointment->start_time)->format('g:i A');
    $end = Carbon::parse($appointment->end_time)->format('g:i A');
    $date = $appointment->booking_date->format('l, F j, Y');
    $salon = $appointment->organization?->name ?? 'the salon';
@endphp
@component('mail::message')
# You're booked, {{ $appointment->customer->name }}!

Thanks for booking with **{{ $salon }}**. Here are your appointment details:

@component('mail::table')
|                 |                                          |
| --------------- | ---------------------------------------- |
| **Service**     | {{ $appointment->service->name }}        |
| **Professional**| {{ $appointment->staff->name }}          |
| **When**        | {{ $date }} · {{ $start }}–{{ $end }}    |
| **Location**    | {{ $appointment->branch->name }}         |
| **Status**      | {{ ucfirst($appointment->status->value) }} |
@endcomponent

If you need to change or cancel, just reply to this email or call the salon.

See you soon,<br>
{{ $salon }}
@endcomponent
