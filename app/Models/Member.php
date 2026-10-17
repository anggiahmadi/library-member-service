<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    public const ACTIVE = 'ACTIVE';
    public const INACTIVE = 'INACTIVE';

    protected $fillable = [
        'member_number',
        'name',
        'email',
        'status',
    ];

    public function lendings(): HasMany
    {
        return $this->hasMany(Lending::class);
    }
}
