<?php

namespace App\Exports;

use App\Models\UserLog;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UserLogsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        return UserLog::query()
            ->with('user')
            ->when($this->filters['date_from'] ?? null, fn($q, $date) =>
                $q->whereDate('created_at', '>=', $date)
            )
            ->when($this->filters['date_to'] ?? null, fn($q, $date) =>
                $q->whereDate('created_at', '<=', $date)
            )
            ->when($this->filters['user_id'] ?? null, fn($q, $id) =>
                $q->where('user_id', $id)
            )
            ->when($this->filters['action'] ?? null, fn($q, $action) =>
                $q->where('action', $action)
            )
            ->when($this->filters['module'] ?? null, fn($q, $module) =>
                $q->where('module', $module)
            )
            ->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return ['No', 'Waktu', 'User', 'Action', 'Module', 'IP Address'];
    }

    public function map($log): array
    {
        static $no = 0;
        $no++;

        return [
            $no,
            $log->created_at->format('d M Y, H:i'),
            $log->user->id_custom ?? '-',
            $log->action,
            $log->module,
            $log->ip_address,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => 'solid',
                    'startColor' => ['rgb' => '4F46E5'],
                ],
            ],
        ];
    }
}