@extends('layouts.admin')

@section('title', 'Reset requests')

@section('content')
    <h1>Password reset requests</h1>

    @if ($officeVerificationCount > 0)
        <div class="flash flash-status">
            <a href="{{ route('admin.requests.index', ['status' => 'needs_office_verification']) }}">
                {{ $officeVerificationCount }} request(s) awaiting office verification
            </a>
        </div>
    @endif

    <div class="card">
        <form method="get" action="{{ route('admin.requests.index') }}">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="">All</option>
                @foreach ($statuses as $case)
                    <option value="{{ $case->value }}" @selected($status === $case->value)>{{ str_replace('_', ' ', $case->value) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary" style="margin-top:0.5rem">Filter</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Kiosk</th>
                    <th>Status</th>
                    <th>Score</th>
                    <th>Requested</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requests as $request)
                    <tr>
                        <td>{{ $request->id }}</td>
                        <td>{{ $request->student->name }}<br><span class="muted">{{ $request->student->email }}</span></td>
                        <td>{{ $request->kiosk->name }}</td>
                        <td><span class="badge badge-{{ $request->status->value }}">{{ str_replace('_', ' ', $request->status->value) }}</span></td>
                        <td>{{ $request->challenge_score }}</td>
                        <td>{{ $request->requested_at?->format('Y-m-d H:i') }}</td>
                        <td><a href="{{ route('admin.requests.show', $request) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $requests->links() }}
    </div>
@endsection
