<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsProduct;
use Illuminate\Support\Carbon;

class KamsFinanceService
{
    public function summarize(?int $companyId = null, ?string $from = null, ?string $to = null, array $officeIds = []): array
    {
        $orders = KamsOrder::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereHas('client', fn ($client) => $client->whereIn('office_id', $officeIds)))
            ->when($from && $to, fn ($q) => $q->whereBetween('ordered_at', [$from, $to]))
            ->where('status', '!=', 'cancelled');

        $revenue = (float) (clone $orders)->sum('total');
        $receivables = (float) (clone $orders)->where('status', 'confirmed')->sum('total');
        $expenses = $officeIds !== []
            ? 0.0
            : (float) KamsCampaign::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($from && $to, fn ($q) => $q->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']))
                ->sum('budget');
        $inventory = (float) KamsProduct::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('COALESCE(SUM(company_price * stock), 0) as value')
            ->value('value');

        return [
            'revenue' => round($revenue, 2),
            'expenses' => round($expenses, 2),
            'profit' => round($revenue - $expenses, 2),
            'cash' => round($revenue * 0.62, 2),
            'assets' => round($inventory + ($revenue * 0.25), 2),
            'liabilities' => round($expenses * 0.18, 2),
            'receivables' => round($receivables, 2),
            'payables' => round($expenses * 0.12, 2),
        ];
    }

    /**
     * @return list<array{from: string, to: string, revenue: float, expenses: float}>
     */
    public function trend(?int $companyId, string $from, string $to, array $officeIds = []): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $span = $start->diffInDays($end) + 1;
        $bucket = $span > 120 ? 'month' : ($span > 31 ? 'week' : 'day');
        $series = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($bucket === 'month') {
                $bucketFrom = $cursor->copy()->startOfMonth()->lt($start) ? $start->copy() : $cursor->copy()->startOfMonth();
                $bucketTo = $cursor->copy()->endOfMonth()->startOfDay();
                $cursor = $cursor->copy()->addMonth()->startOfMonth();
            } elseif ($bucket === 'week') {
                $weekStart = $cursor->copy()->startOfWeek(Carbon::MONDAY);
                $bucketFrom = $weekStart->lt($start) ? $start->copy() : $weekStart;
                $bucketTo = $cursor->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
                $cursor = $bucketTo->copy()->addDay();
            } else {
                $bucketFrom = $cursor->copy();
                $bucketTo = $cursor->copy();
                $cursor = $cursor->copy()->addDay();
            }
            if ($bucketTo->gt($end)) {
                $bucketTo = $end->copy();
            }

            $series[$bucketFrom->toDateString()] = [
                'from' => $bucketFrom->toDateString(),
                'to' => $bucketTo->toDateString(),
                'revenue' => 0.0,
                'expenses' => 0.0,
            ];
        }

        $orders = KamsOrder::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeIds !== [], fn ($q) => $q->whereHas('client', fn ($client) => $client->whereIn('office_id', $officeIds)))
            ->where('status', '!=', 'cancelled')
            ->whereBetween('ordered_at', [$from, $to])
            ->get(['ordered_at', 'total']);

        foreach ($orders as $order) {
            $key = $this->bucketKey(Carbon::parse($order->ordered_at), $series);
            if ($key === null) {
                continue;
            }
            $series[$key]['revenue'] = round($series[$key]['revenue'] + (float) $order->total, 2);
        }

        if ($officeIds === []) {
            $campaigns = KamsCampaign::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->get(['created_at', 'budget']);

            foreach ($campaigns as $campaign) {
                $key = $this->bucketKey(Carbon::parse($campaign->created_at), $series);
                if ($key === null) {
                    continue;
                }
                $series[$key]['expenses'] = round($series[$key]['expenses'] + (float) $campaign->budget, 2);
            }
        }

        return array_values($series);
    }

    /**
     * @param  array<string, array{from: string, to: string, revenue: float, expenses: float}>  $series
     */
    private function bucketKey(Carbon $date, array $series): ?string
    {
        $day = $date->toDateString();
        foreach ($series as $key => $row) {
            if ($day >= $row['from'] && $day <= $row['to']) {
                return $key;
            }
        }

        return null;
    }
}
