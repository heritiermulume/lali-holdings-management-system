<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'office_id', 'sale_price'])]
class KamsProductOfficePrice extends Model
{
    protected function casts(): array
    {
        return ['sale_price' => 'float'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KamsProduct::class, 'product_id');
    }
}
