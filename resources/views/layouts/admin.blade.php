<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') — {{ config('app.name') }}</title>
    <style>
        :root {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            font-size: 16px;
            line-height: 1.5;
            color: #111827;
            background: #f3f4f6;
        }
        body { margin: 0; }
        header {
            background: #1e3a5f;
            color: #fff;
            padding: 0.75rem 1.5rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 1rem;
        }
        header a { color: #dbeafe; text-decoration: none; margin-right: 1rem; }
        header a:hover, header a.active { color: #fff; text-decoration: underline; }
        header .brand { font-weight: 700; margin-right: auto; color: #fff; text-decoration: none; }
        main { max-width: 72rem; margin: 0 auto; padding: 1.5rem; }
        h1 { font-size: 1.5rem; margin: 0 0 1rem; }
        h2 { font-size: 1.15rem; margin: 1.5rem 0 0.75rem; }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); }
        .stat { text-align: center; }
        .stat strong { display: block; font-size: 1.75rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e7eb; }
        th { background: #f9fafb; font-weight: 600; }
        .badge {
            display: inline-block;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-approved_processing { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-denied { background: #fee2e2; color: #991b1b; }
        .badge-needs_office_verification { background: #ede9fe; color: #5b21b6; }
        .badge-failed, .badge-expired { background: #f3f4f6; color: #374151; }
        .flash { padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .flash-status { background: #ecfdf5; border: 1px solid #6ee7b7; }
        .flash-error { background: #fef2f2; border: 1px solid #fca5a5; }
        .flash-secret { background: #fffbeb; border: 1px solid #fcd34d; font-family: monospace; word-break: break-all; }
        .btn {
            display: inline-block;
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            border: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-secondary { background: #e5e7eb; color: #111; }
        form.inline { display: inline; }
        label { display: block; margin: 0.5rem 0; font-weight: 600; }
        input[type=text], input[type=email], input[type=password], select {
            width: 100%;
            max-width: 24rem;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .muted { color: #6b7280; font-size: 0.9rem; }
        .photo-thumb { max-width: 200px; border-radius: 6px; border: 1px solid #e5e7eb; }
        .actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ route('admin.dashboard') }}">{{ config('app.name') }} Admin</a>
        <nav>
            <a href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a href="{{ route('admin.requests.index') }}">Requests</a>
            <a href="{{ route('admin.kiosks.index') }}">Kiosks</a>
            <a href="{{ route('admin.students.index') }}">Students</a>
            <a href="{{ route('admin.audit.index') }}">Audit</a>
            <a href="{{ route('admin.reports.failed-attempts') }}">Failed attempts</a>
        </nav>
        <form method="post" action="{{ route('admin.logout') }}" class="inline">
            @csrf
            <button type="submit" class="btn btn-secondary">Log out</button>
        </form>
    </header>
    <main>
        @if (session('status'))
            <div class="flash flash-status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="flash flash-error">{{ session('error') }}</div>
        @endif
        @if (session('enrollment_code'))
            <div class="flash flash-secret">
                <strong>Enrollment code (shown once):</strong> {{ session('enrollment_code') }}
            </div>
        @endif
        @if (session('kiosk_secret'))
            <div class="flash flash-secret">
                <strong>Kiosk secret (shown once):</strong> {{ session('kiosk_secret') }}
                @if (session('kiosk_secret_for') && isset($kiosk) && (int) session('kiosk_secret_for') === $kiosk->id)
                    <div style="margin-top:0.5rem;">
                        <a class="btn btn-secondary" href="{{ route('admin.kiosks.provisioning-bundle', $kiosk) }}">Download agent.conf</a>
                    </div>
                @endif
            </div>
        @endif
        @yield('content')
    </main>
</body>
</html>
