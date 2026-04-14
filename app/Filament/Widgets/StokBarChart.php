<?php

namespace App\Filament\Widgets;

use App\Models\Inbound;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Filament\Widgets\Widget;

class StokBarChart extends Widget
{
    protected string $view = 'filament.widgets.stok-bar-chart';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role === 'Staff Administrasi Gudang';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = Material::query()
            ->leftJoin('material_pieces as mp', function ($join): void {
                $join->on('materials.id', '=', 'mp.material_id')
                    ->whereNull('mp.deleted_at');
            })
            ->select('materials.id', 'materials.nama_material')
            ->selectRaw("COALESCE(SUM(CASE WHEN mp.status = 'available' THEN mp.panjang ELSE 0 END), 0) as available_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN mp.status = 'booked' THEN mp.panjang ELSE 0 END), 0) as booked_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN mp.status = 'used' THEN mp.panjang ELSE 0 END), 0) as used_total")
            ->groupBy('materials.id', 'materials.nama_material')
            ->orderBy('materials.nama_material')
            ->get();

        $categories = $rows->pluck('nama_material')->values()->all();
        $availableSeries = $rows->map(fn ($row): float => round((float) ($row->available_total ?? 0), 2))->values()->all();
        $bookedSeries = $rows->map(fn ($row): float => round((float) ($row->booked_total ?? 0), 2))->values()->all();
        $usedSeries = $rows->map(fn ($row): float => round((float) ($row->used_total ?? 0), 2))->values()->all();

        $composition = [
            'available' => round(array_sum($availableSeries), 2),
            'booked' => round(array_sum($bookedSeries), 2),
            'used' => round(array_sum($usedSeries), 2),
        ];

        $totalStockOnHand = round($composition['available'] + $composition['booked'], 2);
        $waitingPoCount = (int) PurchaseOrder::query()->where('status', 'waiting_inbound')->count();
        $inboundThisMonth = (float) Inbound::query()
            ->whereDate('created_at', '>=', now()->startOfMonth()->toDateString())
            ->sum('quantity');

        $startMonth = now()->startOfMonth()->subMonths(11);
        $monthKeys = collect(range(0, 11))
            ->map(fn (int $offset): string => $startMonth->copy()->addMonths($offset)->format('Y-m'))
            ->values();

        $monthlyInboundCollection = Inbound::query()
            ->select(['entry_date', 'created_at'])
            ->where(function ($query) use ($startMonth): void {
                $query->whereDate('entry_date', '>=', $startMonth->toDateString())
                    ->orWhereDate('created_at', '>=', $startMonth->toDateString());
            })
            ->get();

        $monthlyInboundRaw = $monthlyInboundCollection
            ->map(function (Inbound $inbound): string {
                $date = $inbound->entry_date ?? $inbound->created_at;

                return Carbon::parse($date)->format('Y-m');
            })
            ->countBy();

        $monthlyInboundQtyRaw = $monthlyInboundCollection
            ->groupBy(function (Inbound $inbound): string {
                $date = $inbound->entry_date ?? $inbound->created_at;

                return Carbon::parse($date)->format('Y-m');
            })
            ->map(fn ($rows): float => round((float) collect($rows)->sum(fn (Inbound $row): float => (float) ($row->quantity ?? 0)), 2));

        $inboundMonths = $monthKeys
            ->map(fn (string $key): string => Carbon::createFromFormat('Y-m', $key)->translatedFormat('M Y'))
            ->all();

        $inboundSeries = $monthKeys
            ->map(fn (string $key): int => (int) ($monthlyInboundRaw[$key] ?? 0))
            ->all();

        $inboundQtySeries = $monthKeys
            ->map(fn (string $key): float => (float) ($monthlyInboundQtyRaw[$key] ?? 0.0))
            ->all();

        $topMaterialRows = collect($categories)
            ->map(function (string $materialName, int $index) use ($availableSeries): array {
                return [
                    'material' => $materialName,
                    'available' => (float) ($availableSeries[$index] ?? 0),
                ];
            })
            ->sortByDesc('available')
            ->take(8)
            ->values();

        $topMaterialsCategories = $topMaterialRows->pluck('material')->all();
        $topMaterialsSeries = $topMaterialRows->pluck('available')->map(fn ($value): float => round((float) $value, 2))->all();

        $availablePieces = MaterialPiece::query()
            ->select(['id', 'created_at'])
            ->where('status', 'available')
            ->whereNotNull('created_at')
            ->get();

        $agingBuckets = [
            '<=30 Hari' => 0,
            '31-60 Hari' => 0,
            '61-90 Hari' => 0,
            '>90 Hari' => 0,
        ];

        foreach ($availablePieces as $piece) {
            $age = now()->diffInDays($piece->created_at);

            if ($age <= 30) {
                $agingBuckets['<=30 Hari']++;
                continue;
            }

            if ($age <= 60) {
                $agingBuckets['31-60 Hari']++;
                continue;
            }

            if ($age <= 90) {
                $agingBuckets['61-90 Hari']++;
                continue;
            }

            $agingBuckets['>90 Hari']++;
        }

        $agingLabels = array_keys($agingBuckets);
        $agingSeries = array_values($agingBuckets);

        $riskSeries = [];
        foreach ($monthKeys as $key) {
            $monthStart = Carbon::createFromFormat('Y-m', $key)->startOfMonth();
            $monthEnd = Carbon::createFromFormat('Y-m', $key)->endOfMonth();

            $monthPieces = MaterialPiece::query()
                ->where('status', 'available')
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->get(['created_at']);

            $total = max(1, $monthPieces->count());
            $old = $monthPieces->filter(fn (MaterialPiece $piece): bool => now()->diffInDays($piece->created_at) > 90)->count();
            $riskSeries[] = round(($old / $total) * 100, 2);
        }

        return [
            'chartBaseId' => 'stok-charts-' . $this->getId(),
            'kpiCards' => [
                ['label' => 'Material Available', 'value' => number_format($composition['available'], 2), 'suffix' => 'm'],
                ['label' => 'Stock On Hand', 'value' => number_format($totalStockOnHand, 2), 'suffix' => 'm'],
                ['label' => 'Inbound Bulan Ini', 'value' => number_format($inboundThisMonth, 2), 'suffix' => 'qty'],
                ['label' => 'PO Waiting Inbound', 'value' => number_format($waitingPoCount), 'suffix' => 'PO'],
            ],
            'categories' => $categories,
            'availableSeries' => $availableSeries,
            'bookedSeries' => $bookedSeries,
            'usedSeries' => $usedSeries,
            'compositionSeries' => array_values($composition),
            'inboundMonths' => $inboundMonths,
            'inboundSeries' => $inboundSeries,
            'inboundQtySeries' => $inboundQtySeries,
            'topMaterialsCategories' => $topMaterialsCategories,
            'topMaterialsSeries' => $topMaterialsSeries,
            'agingLabels' => $agingLabels,
            'agingSeries' => $agingSeries,
            'riskSeries' => $riskSeries,
        ];
    }
}
