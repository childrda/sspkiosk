@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <h1>Dashboard</h1>

    <div class="card">
        <h2>Reset requests</h2>
        <div class="grid">
            @foreach ($requestCounts as $status => $count)
                <div class="stat">
                    <strong>{{ $count }}</strong>
                    <a href="{{ route('admin.requests.index', ['status' => $status]) }}">{{ str_replace('_', ' ', $status) }}</a>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card grid">
        <div class="stat">
            <strong>{{ $registeredStudents }}</strong>
            <span class="muted">Registered students</span>
        </div>
        <div class="stat">
            <strong>{{ $resetDisabledStudents }}</strong>
            <span class="muted">Reset disabled</span>
        </div>
        <div class="stat">
            <strong>{{ $onlineKiosks }} / {{ $kiosks->count() }}</strong>
            <span class="muted">Kiosks online</span>
        </div>
        <div class="stat">
            <strong>{{ $officeVerificationCount }}</strong>
            <a href="{{ route('admin.requests.index', ['status' => 'needs_office_verification']) }}">Awaiting office verification</a>
        </div>
        <div class="stat">
            <strong>{{ $queueDepth }}</strong>
            <span class="muted">Queued jobs</span>
        </div>
        <div class="stat">
            <strong>{{ $failedJobCount }}</strong>
            <span class="muted">Failed jobs</span>
        </div>
    </div>

    <div class="card">
        <h2>Recent pending requests</h2>
        @if ($recentPending->isEmpty())
            <p class="muted">No pending requests.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Kiosk</th>
                        <th>Score</th>
                        <th>Requested</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentPending as $request)
                        <tr>
                            <td>{{ $request->student->name }}</td>
                            <td>{{ $request->kiosk->name }}</td>
                            <td>{{ $request->challenge_score }}/{{ count($request->challenge_questions_presented ?? []) }}</td>
                            <td>{{ $request->requested_at?->diffForHumans() }}</td>
                            <td><a href="{{ route('admin.requests.show', $request) }}">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
