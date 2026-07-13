@extends('layouts.admin')

@section('title', 'Students')

@section('content')
    <h1>Student registration lookup</h1>

    <div class="card">
        <p>
            <a class="btn btn-secondary" href="{{ route('admin.students.export') }}">Export registered students (CSV)</a>
            <a class="btn btn-secondary" href="{{ route('admin.students.roster-compare') }}">Compare against SIS roster</a>
        </p>
    </div>

    <div class="card">
        <form method="get" action="{{ route('admin.students.index') }}">
            <label for="q">Search by name, email, or Google sub</label>
            <input type="text" name="q" id="q" value="{{ $query }}" placeholder="alex@students.example.org">
            <button type="submit" class="btn btn-primary" style="margin-top:0.5rem">Search</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Registered</th>
                    <th>Reset enabled</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    <tr>
                        <td>{{ $student->name }}</td>
                        <td>{{ $student->email }}</td>
                        <td>{{ $student->registered_at?->format('Y-m-d') ?? 'No' }}</td>
                        <td>{{ $student->reset_enabled ? 'Yes' : 'No' }}</td>
                        <td><a href="{{ route('admin.students.show', $student) }}">View</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No students found.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $students->links() }}
    </div>
@endsection
