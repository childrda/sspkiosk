@extends('layouts.admin')

@section('title', 'Roster compare results')

@section('content')
    <h1>Roster compare results</h1>

    <p class="muted">Results are shown in this response only. Roster data is not written to the session or stored on disk.</p>

    <div class="card">
        <p><strong>In roster, not registered:</strong> {{ count($comparison['in_roster_not_registered']) }}
            @if (count($comparison['in_roster_not_registered']) > 0)
                — <button type="button" class="btn btn-secondary" id="download-in-roster-not-registered">Download CSV</button>
            @endif
        </p>

        @if (count($comparison['in_roster_not_registered']) > 0)
            <table id="table-in-roster-not-registered">
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
                — <button type="button" class="btn btn-secondary" id="download-registered-not-in-roster">Download CSV</button>
            @endif
        </p>

        @if (count($comparison['registered_not_in_roster']) > 0)
            <table id="table-registered-not-in-roster">
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

    <script>
        (function () {
            function downloadTableCsv(tableId, filename) {
                const table = document.getElementById(tableId);

                if (!table) {
                    return;
                }

                const rows = Array.from(table.querySelectorAll('tr')).map(function (row) {
                    return Array.from(row.querySelectorAll('th, td')).map(function (cell) {
                        const value = cell.textContent.trim().replace(/"/g, '""');

                        return '"' + value + '"';
                    }).join(',');
                });

                const blob = new Blob([rows.join('\n')], { type: 'text/csv;charset=utf-8' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.click();
                URL.revokeObjectURL(link.href);
            }

            const inRosterBtn = document.getElementById('download-in-roster-not-registered');

            if (inRosterBtn) {
                inRosterBtn.addEventListener('click', function () {
                    downloadTableCsv('table-in-roster-not-registered', 'in_roster_not_registered-{{ now()->format('Y-m-d') }}.csv');
                });
            }

            const registeredBtn = document.getElementById('download-registered-not-in-roster');

            if (registeredBtn) {
                registeredBtn.addEventListener('click', function () {
                    downloadTableCsv('table-registered-not-in-roster', 'registered_not_in_roster-{{ now()->format('Y-m-d') }}.csv');
                });
            }
        })();
    </script>
@endsection
