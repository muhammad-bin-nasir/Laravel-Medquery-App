<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'business_id',
        'business_client_id',
        'workspace_id',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'business_id' => 'string',
            'business_client_id' => 'string',
        ];
    }
}
