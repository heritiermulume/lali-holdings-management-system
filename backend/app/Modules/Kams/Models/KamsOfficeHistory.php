<?php

namespace App\Modules\Kams\Models;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'office_id', 'changed_by', 'field', 'old_value', 'new_value', 'snapshot', 'notes'])]
class KamsOfficeHistory extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
