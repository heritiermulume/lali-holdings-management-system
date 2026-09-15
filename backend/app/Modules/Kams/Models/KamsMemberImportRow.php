<?php

namespace App\Modules\Kams\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_id', 'member_id', 'line_number', 'payload', 'issues', 'status',
    'verified_by', 'verified_at', 'notes',
])]
class KamsMemberImportRow extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'issues' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(KamsMemberImport::class, 'import_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KamsMember::class, 'member_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
