<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsStockJournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class StockJournal
{
    public static function record(string $action, ?Model $model = null, array $old = [], array $new = []): KamsStockJournalEntry
    {
        $request = request();

        return KamsStockJournalEntry::query()->create([
            'company_id' => self::resolveCompanyId($model, $old, $new),
            'office_id' => self::resolveOfficeId($model, $old, $new),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $model ? $model::class : 'system',
            'auditable_id' => $model?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
        ]);
    }

    private static function resolveCompanyId(?Model $model, array $old, array $new): ?int
    {
        $value = $model?->getAttribute('company_id')
            ?? ($new['company_id'] ?? null)
            ?? ($old['company_id'] ?? null);

        return $value !== null ? (int) $value : null;
    }

    private static function resolveOfficeId(?Model $model, array $old, array $new): ?int
    {
        $value = $model?->getAttribute('office_id')
            ?? ($new['office_id'] ?? null)
            ?? ($old['office_id'] ?? null);

        return $value !== null ? (int) $value : null;
    }
}
