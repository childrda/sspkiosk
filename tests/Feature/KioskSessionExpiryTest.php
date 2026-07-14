<?php

namespace Tests\Feature;

use App\Models\Kiosk;
use App\Services\AdminKioskService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KioskSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function configureKioskReset(): void
    {
        config([
            'kiosk.allowed_networks' => ['10.10.0.0/16'],
            'student-password-reset.reset_password_mode' => 'temporary_generated',
            'kiosk.staleness_window' => [
                'start' => '07:00',
                'end' => '16:00',
                'timezone' => 'America/New_York',
            ],
        ]);
    }

    private function browserEnrolledKiosk(array $attributes = []): Kiosk
    {
        return Kiosk::factory()->browserEnrolled()->create(array_merge([
            'allowed_ip' => '10.10.20.15',
        ], $attributes));
    }

    public function test_kiosk_html_post_with_invalid_csrf_redirects_to_start_with_error(): void
    {
        $this->configureKioskReset();
        $this->browserEnrolledKiosk();

        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app['env'] = 'local';

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.15'])
            ->post(route('kiosk.reset.lookup'), [
                '_token' => 'definitely-invalid-csrf-token',
                'identifier' => 'student@students.example.org',
            ]);

        $response->assertRedirect(route('kiosk.reset.index'));
        $response->assertSessionHas('error', 'This screen timed out. Please start again.');
    }

    public function test_session_heartbeat_with_invalid_csrf_still_returns_419_json(): void
    {
        $this->configureKioskReset();
        $this->browserEnrolledKiosk();

        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app['env'] = 'local';

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.15'])
            ->withHeaders([
                'Accept' => 'application/json',
                'X-CSRF-TOKEN' => 'definitely-invalid-csrf-token',
            ])
            ->post(route('kiosk.session-heartbeat'));

        $response->assertStatus(419);
    }

    public function test_non_kiosk_token_mismatch_does_not_redirect_to_kiosk_start(): void
    {
        $this->configureKioskReset();

        Route::middleware('web')->post('/__test/admin-csrf', fn () => response('ok'));

        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app['env'] = 'local';

        $response = $this->post('/__test/admin-csrf', [
            '_token' => 'definitely-invalid-csrf-token',
        ]);

        $response->assertStatus(419);
        $this->assertFalse($response->isRedirect());
    }

    public function test_fresh_get_after_empty_session_rebinds_kiosk_by_reserved_ip(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->browserEnrolledKiosk([
            'allowed_ip' => '10.10.20.15',
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.15'])
            ->get(route('kiosk.reset.index'));

        $response->assertOk();
        $response->assertSessionHas(config('kiosk.registration_session_kiosk_key'), $kiosk->id);
        $response->assertSee('Password reset help', false);
    }

    public function test_last_seen_status_reports_asleep_outside_staleness_window(): void
    {
        $this->configureKioskReset();

        $kiosk = $this->browserEnrolledKiosk([
            'last_seen_at' => now()->subHours(6),
        ]);

        $service = app(AdminKioskService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-14 22:00:00', 'America/New_York'));
        $this->assertSame('asleep', $service->lastSeenStatus($kiosk));
        $this->assertFalse($service->isOnline($kiosk));

        Carbon::setTestNow(Carbon::parse('2026-07-14 10:00:00', 'America/New_York'));
        $this->assertSame('stale', $service->lastSeenStatus($kiosk));
        $this->assertFalse($service->isOnline($kiosk));

        $kiosk->forceFill(['last_seen_at' => now()])->save();
        $this->assertSame('fresh', $service->lastSeenStatus($kiosk->fresh()));
        $this->assertTrue($service->isOnline($kiosk->fresh()));

        Carbon::setTestNow();
    }

    public function test_token_mismatch_handler_scopes_to_kiosk_html_only(): void
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $exception = new TokenMismatchException('CSRF token mismatch.');

        $kioskRequest = \Illuminate\Http\Request::create('/kiosk/reset/lookup', 'POST');
        $kioskRequest->headers->set('Accept', 'text/html');
        $kioskHtml = $handler->render($kioskRequest, $exception);
        $this->assertTrue($kioskHtml->isRedirect(route('kiosk.reset.index')));
        $this->assertSame(
            'This screen timed out. Please start again.',
            session('error'),
        );

        $jsonRequest = \Illuminate\Http\Request::create('/kiosk/session-heartbeat', 'POST');
        $jsonRequest->headers->set('Accept', 'application/json');
        $jsonResponse = $handler->render($jsonRequest, $exception);
        $this->assertSame(419, $jsonResponse->getStatusCode());

        $adminRequest = \Illuminate\Http\Request::create('/admin/login', 'POST');
        $adminRequest->headers->set('Accept', 'text/html');
        $adminResponse = $handler->render($adminRequest, $exception);
        $this->assertSame(419, $adminResponse->getStatusCode());
        $this->assertFalse($adminResponse->isRedirect(route('kiosk.reset.index')));
    }
}
