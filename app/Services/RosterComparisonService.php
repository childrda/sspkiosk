<?php

namespace App\Services;

use App\Enums\StudentPhotoType;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class RosterComparisonService
{
    /**
     * @return array{
     *     in_roster_not_registered: list<array{email: string, name: ?string}>,
     *     registered_not_in_roster: list<array{email: string, name: string, school: ?string, grade: ?string}>,
     *     both_count: int,
     * }
     */
    public function compare(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new \RuntimeException('Unable to read roster file.');
        }

        try {
            $headerRow = fgetcsv($handle);

            if ($headerRow === false) {
                return [
                    'in_roster_not_registered' => [],
                    'registered_not_in_roster' => [],
                    'both_count' => 0,
                ];
            }

            $emailIndex = $this->findEmailColumnIndex($headerRow);
            $nameIndex = $this->findNameColumnIndex($headerRow);

            $rosterEmails = [];

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }

                $email = $this->normalizeEmail($row[$emailIndex] ?? null);

                if ($email === null) {
                    continue;
                }

                $name = $nameIndex !== null ? trim((string) ($row[$nameIndex] ?? '')) : null;
                $rosterEmails[$email] = $name !== '' ? $name : null;
            }
        } finally {
            fclose($handle);
        }

        $registeredStudents = Student::query()
            ->whereNotNull('registered_at')
            ->get(['id', 'email', 'name', 'school', 'grade']);

        $registeredEmails = [];

        foreach ($registeredStudents as $student) {
            $normalized = $this->normalizeEmail($student->email);

            if ($normalized === null) {
                continue;
            }

            $registeredEmails[$normalized] = $student;
        }

        $bothCount = 0;
        $inRosterNotRegistered = [];

        foreach ($rosterEmails as $email => $name) {
            if (array_key_exists($email, $registeredEmails)) {
                $bothCount++;

                continue;
            }

            $inRosterNotRegistered[] = [
                'email' => $email,
                'name' => $name,
            ];
        }

        $registeredNotInRoster = [];

        foreach ($registeredEmails as $email => $student) {
            if (array_key_exists($email, $rosterEmails)) {
                continue;
            }

            $registeredNotInRoster[] = [
                'email' => $student->email,
                'name' => $student->name,
                'school' => $student->school,
                'grade' => $student->grade,
            ];
        }

        usort($inRosterNotRegistered, fn (array $a, array $b): int => strcmp($a['email'], $b['email']));
        usort($registeredNotInRoster, fn (array $a, array $b): int => strcmp($a['email'], $b['email']));

        return [
            'in_roster_not_registered' => $inRosterNotRegistered,
            'registered_not_in_roster' => $registeredNotInRoster,
            'both_count' => $bothCount,
        ];
    }

    /**
     * @param  list<string|null>  $headerRow
     */
    public function findEmailColumnIndex(array $headerRow): ?int
    {
        foreach ($headerRow as $index => $header) {
            if ($this->normalizeHeader((string) $header) === 'email') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $headerRow
     */
    public function findNameColumnIndex(array $headerRow): ?int
    {
        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader((string) $header);

            if (in_array($normalized, ['name', 'student_name', 'full_name'], true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return list<string>
     */
    public function readableHeaders(array $headerRow): array
    {
        return array_values(array_filter(array_map(
            fn ($header): string => trim((string) $header),
            $headerRow,
            array_keys($headerRow),
        ), fn (string $header): bool => $header !== ''));
    }

    private function normalizeHeader(string $header): string
    {
        return Str::lower(trim($header));
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $normalized = Str::lower(trim((string) $email));

        return $normalized === '' ? null : $normalized;
    }
}
