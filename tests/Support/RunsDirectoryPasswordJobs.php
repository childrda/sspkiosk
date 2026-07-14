<?php

namespace Tests\Support;

use App\Contracts\DirectoryPasswordResetter;
use App\Jobs\ResetDirectoryPasswordsJob;
use App\Models\Student;
use App\Services\AuditLogService;
use App\Services\DirectoryPasswordResetCoordinator;
use App\Services\PendingPasswordService;
use App\Services\SlackApprovalService;
use Mockery;

trait RunsDirectoryPasswordJobs
{
    /**
     * @param  object|null  $google  Mock expecting resetPassword (optional setup of key/isConfigured/supports added here)
     */
    protected function runDirectoryPasswordJob(int $requestId, ?object $google = null, ?object $activeDirectory = null): void
    {
        config(['active-directory.enabled' => false]);

        if ($google === null) {
            $google = Mockery::mock(DirectoryPasswordResetter::class);
            $google->shouldReceive('resetPassword')->once();
        }

        $google->shouldReceive('key')->andReturn('google')->byDefault();
        $google->shouldReceive('isConfigured')->andReturn(true)->byDefault();
        $google->shouldReceive('supports')->andReturn(true)->byDefault();

        if ($activeDirectory === null) {
            $activeDirectory = Mockery::mock(DirectoryPasswordResetter::class);
            $activeDirectory->shouldReceive('key')->andReturn('active_directory');
            $activeDirectory->shouldReceive('isConfigured')->andReturn(false);
            $activeDirectory->shouldReceive('supports')->andReturn(true);
            $activeDirectory->shouldNotReceive('resetPassword');
        } else {
            $activeDirectory->shouldReceive('key')->andReturn('active_directory')->byDefault();
            $activeDirectory->shouldReceive('supports')->andReturn(true)->byDefault();
        }

        $coordinator = new DirectoryPasswordResetCoordinator(
            [$google, $activeDirectory],
            app(PendingPasswordService::class),
            app(AuditLogService::class),
            app(SlackApprovalService::class),
            app(\App\Services\PasswordRevisionService::class),
        );

        (new ResetDirectoryPasswordsJob($requestId))->handle($coordinator);
    }

    protected function makeDirectoryFake(string $key, bool $configured = true): DirectoryPasswordResetter
    {
        return new class($key, $configured) implements DirectoryPasswordResetter
        {
            /** @var list<array{0: Student, 1: string, 2: bool}> */
            public array $calls = [];

            public ?\Throwable $throw = null;

            public function __construct(
                private readonly string $directoryKey,
                private readonly bool $configured,
            ) {}

            public function key(): string
            {
                return $this->directoryKey;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function supports(Student $student): bool
            {
                return true;
            }

            public function resetPassword(Student $student, string $password, bool $changePasswordAtNextLogin): void
            {
                $this->calls[] = [$student, $password, $changePasswordAtNextLogin];

                if ($this->throw !== null) {
                    throw $this->throw;
                }
            }
        };
    }
}
