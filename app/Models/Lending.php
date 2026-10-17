<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lending extends Model
{
    public const PENDING = 'PENDING';
    public const ACTIVE = 'ACTIVE';
    public const RETURNED = 'RETURNED';
    public const LOST = 'LOST';
    public const REJECTED = 'REJECTED';
    public const RETURN_PENDING = 'RETURN_PENDING';
    public const LOST_PENDING = 'LOST_PENDING';

    protected $fillable = [
        'member_id',
        'book_copy_id',
        'reservation_reference',
        'borrowed_at',
        'due_at',
        'returned_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'borrowed_at' => 'date',
            'due_at' => 'date',
            'returned_at' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function overdueDays(): int
    {
        $endDate = \Illuminate\Support\Carbon::parse(
            $this->returned_at ?? now()->toDateString()
        );

        $dueDate = \Illuminate\Support\Carbon::parse($this->due_at);

        if ($endDate->lessThanOrEqualTo($dueDate)) {
            return 0;
        }

        return (int) $dueDate->diffInDays($endDate);
    }
}
