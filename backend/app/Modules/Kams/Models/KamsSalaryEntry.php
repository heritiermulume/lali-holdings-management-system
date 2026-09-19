<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'member_id', 'office_id', 'period', 'amount', 'status', 'notes', 'created_by'])]
class KamsSalaryEntry extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
