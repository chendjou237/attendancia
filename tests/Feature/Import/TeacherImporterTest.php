<?php

use App\Enums\EmploymentType;
use App\Models\Teacher;
use App\Models\TeacherBiometricId;
use App\Services\Import\CsvReader;
use App\Services\Import\TeacherImporter;
use App\Services\Attendance\TeacherBiometricIdAssigner;

function writeCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'import_test_').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

function teacherImporter(): TeacherImporter
{
    return new TeacherImporter(new CsvReader, new TeacherBiometricIdAssigner);
}

it('imports valid rows and reports created counts', function () {
    $path = writeCsv(<<<CSV
        staff_no,full_name,employment_type,active_from,biometric_id
        T-0001,Ngwa Fon Peter,hourly,2026-09-01,1001
        T-0002,Achu Rebecca Manka,salaried,2026-09-01,
        CSV);

    $result = teacherImporter()->import($path);

    expect($result->successful())->toBeTrue();
    expect($result->created)->toBe(2);
    expect($result->updated)->toBe(0);
    expect(Teacher::count())->toBe(2);

    $t1 = Teacher::where('staff_no', 'T-0001')->first();
    expect($t1->full_name)->toBe('Ngwa Fon Peter');
    expect($t1->employment_type)->toBe(EmploymentType::Hourly);
    expect(TeacherBiometricId::where('teacher_id', $t1->id)->where('biometric_id', '1001')->exists())->toBeTrue();

    $t2 = Teacher::where('staff_no', 'T-0002')->first();
    expect($t2->employment_type)->toBe(EmploymentType::Salaried);
    expect(TeacherBiometricId::where('teacher_id', $t2->id)->exists())->toBeFalse();
});

it('re-importing an existing staff_no updates rather than duplicates', function () {
    $path1 = writeCsv(<<<CSV
        staff_no,full_name,employment_type,active_from,biometric_id
        T-0001,Ngwa Fon Peter,hourly,2026-09-01,
        CSV);
    teacherImporter()->import($path1);

    $path2 = writeCsv(<<<CSV
        staff_no,full_name,employment_type,active_from,biometric_id
        T-0001,Ngwa Fon P. (corrected),hourly,2026-09-01,
        CSV);
    $result = teacherImporter()->import($path2);

    expect($result->created)->toBe(0);
    expect($result->updated)->toBe(1);
    expect(Teacher::count())->toBe(1);
    expect(Teacher::first()->full_name)->toBe('Ngwa Fon P. (corrected)');
});

it('rejects the whole file and saves nothing when any row is invalid', function () {
    $path = writeCsv(<<<CSV
        staff_no,full_name,employment_type,active_from,biometric_id
        T-0001,Ngwa Fon Peter,hourly,2026-09-01,
        T-0002,Achu Rebecca Manka,not-a-real-type,2026-09-01,
        CSV);

    $result = teacherImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors)->toHaveCount(1);
    expect((string) $result->errors[0])->toContain('Row 3');
    expect(Teacher::count())->toBe(0); // nothing saved, including the valid row
});

it('flags a duplicate staff_no within the same file', function () {
    $path = writeCsv(<<<CSV
        staff_no,full_name,employment_type,active_from,biometric_id
        T-0001,Ngwa Fon Peter,hourly,2026-09-01,
        T-0001,Someone Else,hourly,2026-09-01,
        CSV);

    $result = teacherImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors[0]->message)->toContain('Duplicate staff_no');
});

it('reports a clear error for an unparseable date', function () {
    $path = writeCsv(<<<CSV
        staff_no,full_name,employment_type,active_from,biometric_id
        T-0001,Ngwa Fon Peter,hourly,not-a-date,
        CSV);

    $result = teacherImporter()->import($path);

    expect($result->successful())->toBeFalse();
    expect($result->errors[0]->message)->toContain('not a recognisable date');
});

it('strips a UTF-8 BOM from the header row (Excel Save As CSV on Windows)', function () {
    $path = writeCsv("\xEF\xBB\xBFstaff_no,full_name,employment_type,active_from,biometric_id\nT-0001,Ngwa Fon Peter,hourly,2026-09-01,\n");

    $result = teacherImporter()->import($path);

    expect($result->successful())->toBeTrue();
    expect(Teacher::count())->toBe(1);
});
