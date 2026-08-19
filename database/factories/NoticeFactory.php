<?php

namespace Database\Factories;

use App\Models\Notice;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notice>
 */
class NoticeFactory extends Factory
{
    protected $model = Notice::class;

    public function definition(): array
    {
        return [
            'teacher_id' => Teacher::factory(),
            'type' => 'sick_leave',
            'reference' => null,
            'note' => null,
            'file_path' => null,
            'created_by' => null,
        ];
    }
}
