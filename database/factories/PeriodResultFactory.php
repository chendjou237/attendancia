<?php

namespace Database\Factories;

use App\Enums\PeriodSource;
use App\Enums\PeriodStatus;
use App\Models\ClassCode;
use App\Models\PeriodResult;
use App\Models\PeriodSlot;
use App\Models\RuleVersion;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodResult>
 */
class PeriodResultFactory extends Factory
{
    protected $model = PeriodResult::class;

    public function definition(): array
    {
        return [
            'session_id' => null,
            'teacher_id' => Teacher::factory(),
            'date' => now()->toDateString(),
            'slot_id' => PeriodSlot::factory(),
            'class_code_id' => ClassCode::factory(),
            'status' => PeriodStatus::Present,
            'source' => PeriodSource::Scan,
            'rule_version_id' => RuleVersion::factory(),
            'computed_at' => now(),
            'is_current' => true,
        ];
    }
}
