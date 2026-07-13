@extends('layouts.admin')

@section('title', 'Failed attempts')

@section('content')
    <h1>Failed attempt report</h1>
    <p class="muted">Lockout thresholds: {{ $maxStudentAttempts }} per student / {{ $maxKioskAttempts }} per kiosk per day.</p>

    <div class="card">
        <h2>Failed challenge attempts today</h2>
        @if ($failedToday->isEmpty())
            <p class="muted">None today.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Student</th>
                        <th>Kiosk</th>
                        <th>Score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($failedToday as $request)
                        <tr>
                            <td>{{ $request->created_at->format('H:i') }}</td>
                            <td>{{ $request->student->name }}</td>
                            <td>{{ $request->kiosk->name }}</td>
                            <td>{{ $request->challenge_score }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <h2>Office rejections today</h2>
        <p class="muted">Students who were escalated for office verification but could not be identified in person.</p>
        @if ($officeRejectionsToday->isEmpty())
            <p class="muted">None today.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Student</th>
                        <th>Kiosk</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($officeRejectionsToday as $request)
                        <tr>
                            <td>{{ $request->denied_at?->format('H:i') }}</td>
                            <td><a href="{{ route('admin.students.show', $request->student) }}">{{ $request->student->name }}</a></td>
                            <td>{{ $request->kiosk->name }}</td>
                            <td>{{ $request->denial_reason }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <h2>Students currently locked out</h2>
        @if ($studentLockouts->isEmpty())
            <p class="muted">None.</p>
        @else
            <ul>
                @foreach ($studentLockouts as $student)
                    <li><a href="{{ route('admin.students.show', $student) }}">{{ $student->name }}</a> ({{ $student->email }})</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card">
        <h2>Kiosks currently locked out</h2>
        @if ($kioskLockouts->isEmpty())
            <p class="muted">None.</p>
        @else
            <ul>
                @foreach ($kioskLockouts as $kiosk)
                    <li><a href="{{ route('admin.kiosks.show', $kiosk) }}">{{ $kiosk->name }}</a></li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
