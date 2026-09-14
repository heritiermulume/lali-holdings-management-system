<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'name', 'channel', 'status', 'budget', 'starts_on', 'ends_on', 'description'])]
class KamsCampaign extends Model
{
    protected function casts(): array
    {
        return [
            'budget' => 'float',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
