<?php

namespace Jiannius\Backup\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Jiannius\Backup\Database\Factories\BackupFactory;

class Backup extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * Mass-assignable attributes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'data',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /**
     * Resolve the model's factory.
     */
    protected static function newFactory(): BackupFactory
    {
        return BackupFactory::new();
    }
}
