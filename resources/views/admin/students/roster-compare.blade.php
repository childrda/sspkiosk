@extends('layouts.admin')

@section('title', 'Roster compare')

@section('content')
    <h1>Compare SIS roster</h1>

    <p class="muted">Upload a roster CSV from your SIS. The file must include an email column. It is parsed in memory for this request and is not stored on disk or in your session (default <code>SESSION_DRIVER=database</code> only holds auth state).</p>

    <div class="card">
        <form method="post" action="{{ route('admin.students.roster-compare.run') }}" enctype="multipart/form-data">
            @csrf
            <label for="roster">Roster CSV</label>
            <input type="file" name="roster" id="roster" accept=".csv,.txt,text/csv,text/plain" required>
            @error('roster')
                <p class="flash flash-error">{{ $message }}</p>
            @enderror
            <button type="submit" class="btn btn-primary" style="margin-top:0.75rem">Compare</button>
        </form>
    </div>

    <p><a href="{{ route('admin.students.index') }}">Back to students</a></p>
@endsection
