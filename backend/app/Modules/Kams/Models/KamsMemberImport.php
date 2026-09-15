<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'imported_by', 'source', 'source_label', 'status', 'row_count', 'issue_count', 'imported_at'])]
class KamsMemberImport extends Model
{
    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(KamsMemberImportRow::class, 'import_id');
    }
}
