@extends('layouts.admin')

@section('title', $kiosk->name)

@section('content')
    <h1>{{ $kiosk->name }}</h1>

    @if ($kiosk->trashed())
        <div class="flash">This kiosk is archived. Reset request history is retained.</div>
    @endif

    <div class="card">
        <p><strong>UUID:</strong> {{ $kiosk->kiosk_uuid }}</p>
        <p><strong>Status:</strong> {{ $kiosk->status->value }}</p>
        <p><strong>Enrolled:</strong> {{ $isEnrolled ? 'Yes' : 'No' }}
            @if ($kiosk->enrollment_type)
                ({{ str_replace('_', ' ', $kiosk->enrollment_type->value) }})
            @endif
        </p>
        <p><strong>Last seen:</strong>
            @if ($kiosk->last_seen_at)
                {{ $kiosk->last_seen_at->toDateTimeString() }}
                ({{ $kiosk->last_seen_at->diffForHumans() }})
                @if ($lastSeenStatus === 'stale')
                    <span class="badge badge-expired">stale</span>
                @elseif ($lastSeenStatus === 'asleep')
                    <span class="badge badge-asleep">asleep</span>
                @endif
            @else
                Never
            @endif
        </p>
        <p class="muted">Last seen is advisory only and does not gate kiosk access. Outside school hours, a sleeping Chromebook shows as asleep rather than stale.</p>
        <p><strong>Location:</strong> {{ $kiosk->location ?? '—' }}</p>
        <p><strong>Allowed IP:</strong> {{ $kiosk->allowed_ip ?? '—' }}</p>
        <p><strong>Allowed subnet:</strong> {{ $kiosk->allowed_subnet ?? '—' }}</p>

        @if (session('kiosk_secret') && session('kiosk_secret_for') === $kiosk->id)
            <div class="flash flash-secret" style="margin-top:1rem;">
                <a class="btn btn-secondary" href="{{ route('admin.kiosks.provisioning-bundle', $kiosk) }}">Download agent.conf provisioning bundle</a>
            </div>
        @endif

        <div class="actions">
            @if ($kiosk->trashed())
                <form method="post" action="{{ route('admin.kiosks.restore', $kiosk) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn-primary">Restore kiosk</button>
                </form>
            @else
                @if ($kiosk->status->value === 'active')
                    <form method="post" action="{{ route('admin.kiosks.disable', $kiosk) }}" class="inline" onsubmit="return confirm('Disable this kiosk?');">
                        @csrf
                        <button type="submit" class="btn btn-danger">Disable kiosk</button>
                    </form>
                @else
                    <form method="post" action="{{ route('admin.kiosks.enable', $kiosk) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn-primary">Enable kiosk</button>
                    </form>
                @endif

                @if ($isDeviceAgent)
                    <form method="post" action="{{ route('admin.kiosks.rotate-secret', $kiosk) }}" class="inline" onsubmit="return confirm('Rotate secret? The kiosk must be updated with the new secret.');">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Rotate secret</button>
                    </form>
                @elseif (! $isEnrolled)
                    <form method="post" action="{{ route('admin.kiosks.enrollment-code', $kiosk) }}" class="inline">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Issue enrollment code</button>
                    </form>
                @endif

                @if ($isEnrolled)
                    <form method="post" action="{{ route('admin.kiosks.reset-reenrollment', $kiosk) }}" class="inline" onsubmit="return confirm('Clear enrollment so this kiosk can be set up again?');">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Reset enrollment</button>
                    </form>
                @endif

                <form
                    method="post"
                    action="{{ route('admin.kiosks.destroy', $kiosk) }}"
                    class="inline"
                    onsubmit="return confirm('Archive this kiosk? Reset request history will be kept, but the device can no longer authenticate.');"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Archive kiosk</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card">
        <h2>Recent reset requests</h2>
        @if ($recentRequests->isEmpty())
            <p class="muted">No requests from this kiosk.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Status</th>
                        <th>Requested</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentRequests as $request)
                        <tr>
                            <td><a href="{{ route('admin.requests.show', $request) }}">{{ $request->id }}</a></td>
                            <td>{{ $request->student->name }}</td>
                            <td>{{ $request->status->value }}</td>
                            <td>{{ $request->requested_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
