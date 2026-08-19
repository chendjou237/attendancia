<?php

use App\Http\Controllers\TeacherMonthlyReportPdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/admin/monthly-reports/{report}/teachers/{teacherId}/pdf', TeacherMonthlyReportPdfController::class)
    ->middleware('auth')
    ->name('monthly-reports.teacher-pdf');
