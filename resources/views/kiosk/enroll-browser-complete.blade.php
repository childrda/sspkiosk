@extends('layouts.kiosk')

@section('title', 'Enrollment complete')

@section('content')
    <h1>Enrollment complete</h1>

    <p class="status">{{ $kioskName }} is ready for student password resets.</p>

    <div class="notice">
        <p><strong>Confirm the DHCP reservation.</strong> This Chromebook is identified by its reserved IP address on the school network.</p>
        <p>Allowed IP on this kiosk record: <strong>{{ $allowedIp ?? 'not set' }}</strong></p>
        <p>If the reservation does not match, students at this device will be blocked. Update the kiosk record in admin or fix the reservation before opening the reset flow.</p>
    </div>

    <p class="muted">You can close this tab and open the password reset screen on this device.</p>
@endsection
