<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MonthlyReportPdfController;
use App\Http\Controllers\TeacherMonthlyReportPdfController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/locale/{locale}', LocaleController::class)
    ->name('locale.switch')
    ->whereIn('locale', ['en', 'fr']);

Route::get('/admin/monthly-reports/{report}/pdf', MonthlyReportPdfController::class)
    ->middleware('auth')
    ->name('monthly-reports.pdf');

Route::get('/admin/monthly-reports/{report}/teachers/{teacherId}/pdf', TeacherMonthlyReportPdfController::class)
    ->middleware('auth')
    ->name('monthly-reports.teacher-pdf');
