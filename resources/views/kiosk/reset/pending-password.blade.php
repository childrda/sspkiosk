@extends('layouts.kiosk')

@section('title', 'Write down your password')

@section('content')
    <h1 class="no-print">Write down this password</h1>

    <div class="notice no-print" role="note">{{ $notice }}</div>

    @if ($copyNoticeEnabled)
        <p class="muted no-print">Write it on paper now. You will not be able to see it again on this screen.</p>
    @endif

    <p class="temp-password no-print" id="temp-password" aria-live="polite">{{ $temporaryPassword }}</p>

    @if ($labelPrintingEnabled && $resetRequest->pending_password_printed_at === null)
        <p class="no-print" style="margin-top:1rem;">
            <button type="button" class="btn btn-secondary" id="print-label-btn">Print label</button>
        </p>
    @endif

    <p class="muted no-print">This screen will continue in <span id="countdown">{{ $displaySeconds }}</span> seconds.</p>

    <div class="print-label" aria-hidden="true">
        <div style="font-size:9pt">{{ $studentName }}</div>
        <div class="pw">{{ $temporaryPassword }}</div>
        <div style="font-size:7pt">Change this when you sign in. Do not share.</div>
    </div>

    <script>
        (function () {
            let remaining = {{ $displaySeconds }};
            const countdown = document.getElementById('countdown');
            const passwordEl = document.getElementById('temp-password');
            const printBtn = document.getElementById('print-label-btn');

            const timer = setInterval(function () {
                remaining -= 1;
                countdown.textContent = String(remaining);

                if (remaining <= 0) {
                    clearInterval(timer);
                    passwordEl.textContent = '********';
                    if (printBtn) {
                        printBtn.style.display = 'none';
                    }
                    window.location.href = @json($submittedUrl);
                }
            }, 1000);

            if (printBtn) {
                printBtn.addEventListener('click', function () {
                    fetch(@json(route('kiosk.reset.print', $resetRequest)), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': @json(csrf_token()),
                            'Accept': 'application/json',
                        },
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('print_failed');
                        }

                        printBtn.style.display = 'none';
                        window.print();
                    }).catch(function () {
                        printBtn.disabled = true;
                    });
                });
            }
        })();
    </script>

    <style>
        .temp-password {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-align: center;
            padding: 1.5rem;
            background: #f3f4f6;
            border-radius: 8px;
            word-break: break-word;
        }
    </style>

    <style media="print">
        @page { size: 3.5in 1.125in; margin: 0.05in; }
        body * { visibility: hidden; }
        .print-label, .print-label * { visibility: visible; }
        .print-label { position: absolute; top: 0; left: 0; width: 3.4in; font-family: ui-monospace, monospace; }
        .print-label .pw { font-size: 18pt; font-weight: 700; letter-spacing: 0.06em; }
        .no-print { display: none !important; }
    </style>
@endsection
