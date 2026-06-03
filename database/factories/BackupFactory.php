<?php

namespace Jiannius\Backup\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Jiannius\Backup\Models\Backup;

class BackupFactory extends Factory
{
    /**
     * The model the factory builds.
     *
     * @var class-string<Backup>
     */
    protected $model = Backup::class;

    /**
     * Default attribute state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'data' => [],
        ];
    }
}
