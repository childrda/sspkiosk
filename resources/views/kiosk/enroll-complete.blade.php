@extends('layouts.kiosk')

@section('title', 'Enrollment complete')

@section('content')
    <h1>Enrollment complete</h1>

    <div class="notice" role="alert">
        <strong>Store this device secret now.</strong> It will not be shown again. You need it to run the heartbeat agent.
    </div>

    <p><strong>Kiosk UUID:</strong> <code id="kiosk-uuid">{{ $kioskUuid }}</code></p>

    <label for="kiosk-secret">Device secret (shown once)</label>
    <input type="text" id="kiosk-secret" value="{{ $kioskSecret }}" readonly>

    <div class="actions" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:1rem;">
        <button type="button" class="btn btn-secondary" id="copy-secret">Copy secret</button>
        <button type="button" class="btn btn-secondary" id="download-config">Download agent.conf</button>
    </div>

    <h2 style="font-size:1.25rem;margin-top:1.5rem;">Install the heartbeat agent</h2>
    <p>On this device (as root or with sudo):</p>
    <pre style="background:#f3f4f6;padding:1rem;border-radius:8px;overflow-x:auto;font-size:0.9rem;"><code>sudo bash /path/to/sspkiosk/agent/install.sh
sudo install -m 0600 -o sspkiosk -g sspkiosk /path/to/agent.conf /etc/sspkiosk/agent.conf
sudo systemctl enable --now sspkiosk-agent
sspkiosk-agent check</code></pre>

    <p class="muted">Or enroll from the CLI before installing config manually:</p>
    <pre style="background:#f3f4f6;padding:1rem;border-radius:8px;overflow-x:auto;font-size:0.9rem;"><code>sudo sspkiosk-agent enroll --code YOUR-CODE-HERE</code></pre>

    <p style="margin-top:1.5rem;">
        <a href="{{ route('kiosk.reset.index') }}" class="btn btn-primary">Continue to password reset</a>
    </p>

    <textarea id="agent-config" hidden>{{ $agentConfig }}</textarea>

    <script>
        document.getElementById('copy-secret').addEventListener('click', function () {
            const input = document.getElementById('kiosk-secret');
            input.select();
            input.setSelectionRange(0, input.value.length);
            navigator.clipboard.writeText(input.value);
        });

        document.getElementById('download-config').addEventListener('click', function () {
            const config = document.getElementById('agent-config').value;
            const blob = new Blob([config], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'agent.conf';
            link.click();
            URL.revokeObjectURL(url);
        });
    </script>
@endsection
