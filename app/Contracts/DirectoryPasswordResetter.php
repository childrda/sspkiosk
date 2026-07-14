<?php

namespace App\Contracts;

use App\Models\Student;

interface DirectoryPasswordResetter
{
    public function key(): string;

    public function isConfigured(): bool;

    public function supports(Student $student): bool;

    public function resetPassword(
        Student $student,
        string $password,
        bool $changePasswordAtNextLogin,
    ): void;
}
