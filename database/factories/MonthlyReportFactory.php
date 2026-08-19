<?php

namespace Database\Factories;

use App\Enums\ReportState;
use App\Models\MonthlyReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonthlyReport>
 */
class MonthlyReportFactory extends Factory
{
    protected $model = MonthlyReport::class;

    public function definition(): array
    {
        return [
            'month' => now()->startOfMonth()->toDateString(),
            'state' => ReportState::Draft,
            'snapshot_json' => null,
            'generated_at' => null,
        ];
    }
}
