<?php

namespace App\Modules\Kams\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'company_id', 'communicable_type', 'communicable_id', 'recipient_type', 'recipient_name',
    'recipient_contact', 'message_type', 'trigger', 'channel', 'locale', 'category',
    'subject', 'body', 'delivery_status', 'sent_at',
])]
class KamsCommunication extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function communicable(): MorphTo
    {
        return $this->morphTo();
    }
}
