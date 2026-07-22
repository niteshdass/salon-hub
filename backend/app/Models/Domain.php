<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'domain',
        'is_primary',
        'is_verified',
        'ssl_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'bool',
            'is_verified' => 'bool',
            'ssl_enabled' => 'bool',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
