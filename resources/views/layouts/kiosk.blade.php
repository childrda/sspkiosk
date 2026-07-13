<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Student Password Reset') — {{ config('app.name') }}</title>
    <style>
        :root {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            font-size: 18px;
            line-height: 1.5;
            color: #1a1a1a;
            background: #f4f6f8;
        }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card {
            background: #fff;
            max-width: 42rem;
            width: 100%;
            margin: 1rem;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
        }
        h1 { font-size: 1.75rem; margin: 0 0 1rem; }
        .notice {
            background: #eef4ff;
            border-left: 4px solid #2563eb;
            padding: 1rem;
            margin: 1rem 0;
            font-size: 1rem;
        }
        .alert-error {
            background: #fef2f2;
            border-left: 4px solid #dc2626;
            padding: 1rem;
            margin: 1rem 0;
        }
        .btn {
            display: inline-block;
            margin-top: 1rem;
            padding: 1rem 1.5rem;
            font-size: 1.125rem;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
        }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-secondary { background: #e5e7eb; color: #1a1a1a; }
        p.muted { color: #6b7280; font-size: 0.95rem; }
        p.status { color: #047857; font-weight: 600; }
        label { display: block; margin: 0.75rem 0; font-weight: 600; }
        label input {
            display: block;
            width: 100%;
            margin-top: 0.35rem;
            padding: 0.75rem;
            font-size: 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-sizing: border-box;
        }
        fieldset.question-block {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin: 1rem 0;
            padding: 1rem;
        }
        fieldset.question-block legend { font-weight: 700; padding: 0 0.25rem; }
        .camera-wrap {
            background: #111;
            border-radius: 8px;
            overflow: hidden;
            margin: 1rem 0;
            max-height: 360px;
        }
        #camera { width: 100%; display: block; }
        ul.checklist { padding-left: 1.25rem; font-size: 1.05rem; }
    </style>
</head>
<body>
    <main class="card">
        @yield('content')
    </main>
    <script>
        const HB_URL = @json(route('kiosk.session-heartbeat'));
        const HB_MS = {{ (int) config('kiosk.heartbeat_interval_seconds') * 1000 }};
        const TOKEN = @json(csrf_token());
        const beat = () => fetch(HB_URL, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json'},
            credentials: 'same-origin',
            keepalive: true,
        }).catch(() => {});
        beat();
        setInterval(beat, HB_MS);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) beat(); });
    </script>
</body>
</html>
