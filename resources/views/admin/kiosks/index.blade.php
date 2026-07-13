@extends('layouts.admin')

@section('title', 'Kiosks')

@section('content')
    <h1>Kiosks</h1>

    <p>
        <a href="{{ route('admin.kiosks.index') }}" @if (! $archived) class="muted" @endif>Active</a>
        |
        <a href="{{ route('admin.kiosks.index', ['archived' => 1]) }}" @if ($archived) class="muted" @endif>Archived</a>
    </p>

    @unless ($archived)
        <div class="card">
            <h2>Create kiosk</h2>
            <form method="post" action="{{ route('admin.kiosks.store') }}">
                @csrf
                <label>Name *</label>
                <input type="text" name="name" value="{{ old('name') }}" required>
                <label>School</label>
                <input type="text" name="school" value="{{ old('school') }}">
                <label>Location</label>
                <input type="text" name="location" value="{{ old('location') }}">
                <label>Allowed IP</label>
                <input type="text" name="allowed_ip" value="{{ old('allowed_ip') }}">
                <label>Allowed subnet (CIDR)</label>
                <input type="text" name="allowed_subnet" value="{{ old('allowed_subnet') }}">
                <button type="submit" class="btn btn-primary" style="margin-top:0.75rem">Create kiosk</button>
            </form>
        </div>
    @endunless

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>School</th>
                    <th>Status</th>
                    <th>Online</th>
                    <th>Last seen</th>
                    <th>Requests</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($kiosks as $kiosk)
                    <tr>
                        <td>{{ $kiosk->name }}</td>
                        <td>{{ $kiosk->school ?? '—' }}</td>
                        <td>{{ $kiosk->status->value }}</td>
                        <td>{{ $kiosk->is_online ? 'Yes' : 'No' }}</td>
                        <td>{{ $kiosk->last_seen_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>{{ $kiosk->password_reset_requests_count }}</td>
                        <td>
                            @if ($archived)
                                <form method="post" action="{{ route('admin.kiosks.restore', $kiosk) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary">Restore</button>
                                </form>
                                <a href="{{ route('admin.kiosks.show', $kiosk) }}">View</a>
                            @else
                                <a href="{{ route('admin.kiosks.show', $kiosk) }}">Manage</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No kiosks found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
