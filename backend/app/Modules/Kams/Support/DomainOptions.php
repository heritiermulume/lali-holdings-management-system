<?php

namespace App\Modules\Kams\Support;

class DomainOptions
{
    /**
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        return [
            'client_segments' => ['retail', 'wholesale', 'distributor', 'corporate', 'other'],
            'client_statuses' => ['prospect', 'active', 'inactive'],
            'member_origins' => ['new_recruit', 'migrated'],
            'import_sources' => ['registry', 'database', 'excel', 'google_sheets', 'other'],
            'candidate_sources' => ['poster', 'qr_code', 'online_form', 'sponsorship', 'social_media', 'other'],
            'interview_results' => ['admitted', 'rejected', 'abandoned'],
            'observation_results' => ['approved', 'rejected'],
            'office_types' => ['headquarters', 'office', 'agency', 'branch'],
            'stock_return_types' => ['unsold', 'damaged', 'wrong_item', 'other'],
            'stock_conditions' => ['good', 'damaged', 'expired'],
            'stock_adjust_types' => ['increase', 'decrease', 'set'],
            'movement_types' => ['in', 'out', 'transfer', 'adjustment'],
            'ledger_types' => ['credit', 'debit'],
            'ledger_statuses' => ['posted', 'pending', 'void'],
            'product_statuses' => ['active', 'draft', 'archived'],
        ];
    }
}
