@extends('layouts.admin')

@section('title', 'Roster compare results')

@section('content')
    <h1>Roster compare results</h1>

    <div class="card">
        <p><strong>In roster, not registered:</strong> {{ count($comparison['in_roster_not_registered']) }}
            @if (count($comparison['in_roster_not_registered']) > 0)
                — <a href="{{ route('admin.students.roster-compare.download', 'in_roster_not_registered') }}">Download CSV</a>
            @endif
        </p>

        @if (count($comparison['in_roster_not_registered']) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Name</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($comparison['in_roster_not_registered'] as $row)
                        <tr>
                            <td>{{ $row['email'] }}</td>
                            <td>{{ $row['name'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <p><strong>Registered, not in roster:</strong> {{ count($comparison['registered_not_in_roster']) }}
            @if (count($comparison['registered_not_in_roster']) > 0)
                — <a href="{{ route('admin.students.roster-compare.download', 'registered_not_in_roster') }}">Download CSV</a>
            @endif
        </p>

        @if (count($comparison['registered_not_in_roster']) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Name</th>
                        <th>School</th>
                        <th>Grade</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($comparison['registered_not_in_roster'] as $row)
                        <tr>
                            <td>{{ $row['email'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['school'] ?? '—' }}</td>
                            <td>{{ $row['grade'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <p><strong>In both:</strong> {{ $comparison['both_count'] }}</p>
    </div>

    <p>
        <a href="{{ route('admin.students.roster-compare') }}">Compare another roster</a>
        |
        <a href="{{ route('admin.students.index') }}">Back to students</a>
    </p>
@endsection
