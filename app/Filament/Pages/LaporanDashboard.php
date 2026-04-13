<?php

namespace App\Filament\Pages;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Project;
use App\Models\ProjectItem;
use App\Models\ProjectTemplate;
use App\Models\Material;
use App\Models\MaterialPiece;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\UserLog;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class LaporanDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan Operasional';

    protected string $view = 'filament.pages.laporan-dashboard';

    public string $dateFrom = '';

    public string $dateTo = '';

    /**
     * @var array{date_from:string, date_to:string}
     */
    public array $filters = [
        'date_from' => '',
        'date_to' => '',
        'finance_activity' => 'all',
        'ops_activity' => 'all',
        'ops_pr_status' => 'all',
        'teknik_activity' => 'all',
        'teknik_template_id' => '',
        'gudang_activity' => 'all',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $roleReportData = [];

    /**
     * @var array<int, int>
     */
    public array $expandedRows = [];

    /**
     * @var array<int, int>
     */
    public array $expandedProjectRows = [];

    /**
     * @var array<int, int>
     */
    public array $expandedOutboundProjectRows = [];

    /**
     * @var array<int, int>
     */
    public array $expandedOutboundRows = [];

    /**
     * @var array<int, string>
     */
    public array $expandedAiMatchingRows = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->dateFrom = (string) request()->query('date_from', now()->startOfMonth()->toDateString());
        $this->dateTo = (string) request()->query('date_to', now()->toDateString());
        $this->filters['date_from'] = $this->dateFrom;
        $this->filters['date_to'] = $this->dateTo;
        $this->roleReportData = $this->resolveRoleReportData();

        $this->logCctv('View Laporan', 'Membuka halaman laporan operasional.');
    }

    public static function canAccess(array $parameters = []): bool
    {
        return in_array(auth()->user()?->role, [
            'Finance',
            'Keuangan',
            'Manager Operasional',
            'Manager Operational',
            'Manajer Operasional',
            'Manager Teknik',
            'Manajer Teknik',
            'Manager Gudang',
            'Manajer Gudang',
            'Staff Administrasi Gudang',
            'Staff Adm Gudang',
            'Staff Gudang',
            'Administrator IT',
            'Admin Gudang',
            'Administrator Gudang',
        ], true);
    }

    public function applyFilters(): void
    {
        $this->dateFrom = (string) ($this->filters['date_from'] ?? '');
        $this->dateTo = (string) ($this->filters['date_to'] ?? '');
        $this->filters['finance_activity'] = (string) ($this->filters['finance_activity'] ?? 'all');
        $this->filters['ops_activity'] = (string) ($this->filters['ops_activity'] ?? 'all');
        $this->filters['ops_pr_status'] = (string) ($this->filters['ops_pr_status'] ?? 'all');
        $this->filters['teknik_activity'] = (string) ($this->filters['teknik_activity'] ?? 'all');
        $this->filters['teknik_template_id'] = (string) ($this->filters['teknik_template_id'] ?? '');
        $this->filters['gudang_activity'] = (string) ($this->filters['gudang_activity'] ?? 'all');
        $this->roleReportData = $this->resolveRoleReportData();
        $this->expandedRows = [];
        $this->expandedProjectRows = [];
        $this->expandedOutboundProjectRows = [];
        $this->expandedOutboundRows = [];
        $this->expandedAiMatchingRows = [];

        $this->logCctv(
            'Filter Laporan',
            'Menerapkan filter laporan. date_from=' . ($this->dateFrom ?: '-')
                . ', date_to=' . ($this->dateTo ?: '-')
        );
    }

    public function exportExcel()
    {
        [$headings, $rows] = $this->buildExportRows();

        $filename = 'laporan-operasional-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(new class($headings, $rows) implements FromArray, WithHeadings {
            /**
             * @param  array<int, string>  $headings
             * @param  array<int, array<int, string>>  $rows
             */
            public function __construct(private array $headings, private array $rows)
            {
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function array(): array
            {
                return $this->rows;
            }
        }, $filename);
    }

    public function exportPdf()
    {
        $roleData = $this->resolveRoleReportData();
        $pdfHtml = view('laporan.export-pdf', [
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'roleData' => $roleData,
            'filters' => $this->filters,
            'showFinance' => $this->canViewFinanceSection(),
            'showOperasional' => $this->canViewOperasionalSection(),
            'showTeknik' => $this->canViewTeknikSection(),
            'showGudang' => $this->canViewGudangSection(),
            'roleName' => (string) (auth()->user()?->role ?? '-'),
        ])->render();

        $pdf = Pdf::loadHTML($pdfHtml)->setPaper('a4', 'landscape');
        $filename = 'laporan-operasional-' . now()->format('Ymd-His') . '.pdf';

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    /**
     * @return array<string, int|float>
     */
    public function financeStats(): array
    {
        return [
            'acc_count' => (int) $this->inRange(PurchaseRequest::query()->whereNotNull('approved_by_finance_at'), 'approved_by_finance_at')->count(),
            'pengadaan_count' => (int) $this->inRange(PurchaseOrder::query(), 'created_at')->count(),
            'po_selesai_count' => (int) $this->inRange(PurchaseOrder::query()->where('status', 'completed'), 'created_at')->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function operasionalStats(): array
    {
        return [
            'acc_count' => (int) $this->inRange(PurchaseRequest::query()->whereNotNull('approved_by_manager_at'), 'approved_by_manager_at')->count(),
            'po_ke_supplier' => (int) $this->inRange(PurchaseOrder::query(), 'created_at')->count(),
            'aging_siap_proses' => (int) $this->inRange(
                Task::query()
                    ->where('type', 'ai_deadstock_recommendation')
                    ->where('status', 'pending')
                    ->whereRaw("payload->>'workflow_state' = ?", ['approved_for_aging']),
                'updated_at'
            )->count(),
            'aging_selesai' => (int) $this->inRange(
                Task::query()
                    ->where('type', 'ai_deadstock_recommendation')
                    ->where('status', 'done'),
                'updated_at'
            )->count(),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function teknikStats(): array
    {
        $projects = $this->inRange(Project::query(), 'created_at')->get(['id', 'status', 'created_at']);

        $waitingProjects = $projects->filter(function (Project $project): bool {
            $status = mb_strtolower((string) $project->status);

            return !in_array($status, ['completed', 'complete'], true);
        });

        $avgWaitDays = $waitingProjects->isEmpty()
            ? 0.0
            : round($waitingProjects->avg(fn (Project $project): float => (float) Carbon::parse($project->created_at)->diffInDays(now())), 1);

        return [
            'project_total' => $projects->count(),
            'project_waiting' => $waitingProjects->count(),
            'project_completed' => $projects->count() - $waitingProjects->count(),
            'avg_wait_days' => $avgWaitDays,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    public function gudangStats(): array
    {
        return [
            'inbound_count' => (int) $this->inRange(Inbound::query(), 'created_at')->count(),
            'outbound_taken_count' => (int) $this->inRange(Outbound::query()->where('status', 'taken'), 'updated_at')->count(),
            'task_pending_count' => (int) $this->inRange(Task::query()->where('status', 'pending'), 'created_at')->count(),
            'task_done_count' => (int) $this->inRange(Task::query()->where('status', 'done'), 'updated_at')->count(),
        ];
    }

    public function canViewFinanceSection(): bool
    {
        return $this->isCurrentRole('finance') || $this->isCurrentRole('admin');
    }

    public function canViewOperasionalSection(): bool
    {
        return $this->isCurrentRole('operasional') || $this->isCurrentRole('admin');
    }

    public function canViewTeknikSection(): bool
    {
        return $this->isCurrentRole('teknik') || $this->isCurrentRole('admin');
    }

    public function canViewGudangSection(): bool
    {
        return $this->isCurrentRole('gudang') || $this->isCurrentRole('gudang_manager') || $this->isCurrentRole('admin');
    }

    public function toggleExpand(int $prId): void
    {
        if (in_array($prId, $this->expandedRows, true)) {
            $this->expandedRows = array_values(array_filter(
                $this->expandedRows,
                fn (int $id): bool => $id !== $prId
            ));

            return;
        }

        $this->expandedRows[] = $prId;
    }

    public function toggleProjectExpand(int $projectId): void
    {
        if (in_array($projectId, $this->expandedProjectRows, true)) {
            $this->expandedProjectRows = array_values(array_filter(
                $this->expandedProjectRows,
                fn (int $id): bool => $id !== $projectId
            ));

            return;
        }

        $this->expandedProjectRows[] = $projectId;
    }

    public function toggleOutboundProjectExpand(int $projectId): void
    {
        if (in_array($projectId, $this->expandedOutboundProjectRows, true)) {
            $this->expandedOutboundProjectRows = array_values(array_filter(
                $this->expandedOutboundProjectRows,
                fn (int $id): bool => $id !== $projectId
            ));

            return;
        }

        $this->expandedOutboundProjectRows[] = $projectId;
    }

    public function toggleOutboundExpand(int $outboundId): void
    {
        if (in_array($outboundId, $this->expandedOutboundRows, true)) {
            $this->expandedOutboundRows = array_values(array_filter(
                $this->expandedOutboundRows,
                fn (int $id): bool => $id !== $outboundId
            ));

            return;
        }

        $this->expandedOutboundRows[] = $outboundId;
    }

    public function toggleAiMatchingExpand(string $rowKey): void
    {
        if (in_array($rowKey, $this->expandedAiMatchingRows, true)) {
            $this->expandedAiMatchingRows = array_values(array_filter(
                $this->expandedAiMatchingRows,
                fn (string $id): bool => $id !== $rowKey
            ));

            return;
        }

        $this->expandedAiMatchingRows[] = $rowKey;
    }

    /**
     * @return array<string, string>
     */
    public function teknikTemplateOptions(): array
    {
        $options = ['' => 'Semua Template'];

        ProjectTemplate::query()
            ->orderBy('nama_template')
            ->get(['id', 'nama_template', 'kode_template'])
            ->each(function (ProjectTemplate $template) use (&$options): void {
                $label = trim((string) ($template->nama_template ?: $template->kode_template ?: ('Template #' . $template->id)));
                $options[(string) $template->id] = $label;
            });

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function financeRoleData(): array
    {
        $financeActivity = (string) ($this->filters['finance_activity'] ?? 'all');

        $prs = $this->inRange(
            PurchaseRequest::query()
                ->with(['material:id,nama_material', 'requester:id,name'])
                ->whereNotNull('approved_by_finance_at'),
            'approved_by_finance_at'
        )
            ->latest('approved_by_finance_at')
            ->limit(200)
            ->get();

        $poRows = $this->inRange(
            PurchaseOrder::query()
                ->with(['supplier:id,nama_supplier', 'items:id,purchase_order_id'])
                ->whereNotNull('published_at'),
            'published_at'
        )
            ->latest('published_at')
            ->limit(200)
            ->get();

        return [
            'pr_disetujui_finance' => ($financeActivity === 'all' || $financeActivity === 'pr') ? $prs
                ->map(fn (PurchaseRequest $pr): array => [
                    'no_pr' => $this->formatPrNumber((int) $pr->id),
                    'material' => (string) ($pr->material?->nama_material ?? '-'),
                    'qty' => (string) number_format((float) $pr->quantity, 2, '.', ''),
                    'diajukan_oleh' => (string) ($pr->requester?->name ?? '-'),
                    'tgl_acc_finance' => $this->formatDateTime($pr->approved_by_finance_at),
                ])
                ->all() : [],
            'po_terbit' => ($financeActivity === 'all' || $financeActivity === 'po') ? $poRows
                ->map(fn (PurchaseOrder $po): array => [
                    'no_po' => (string) ($po->po_number ?? '-'),
                    'supplier' => (string) ($po->supplier?->nama_supplier ?? '-'),
                    'total_item' => (int) $po->items->count(),
                    'tgl_terbit' => $this->formatDateTime($po->published_at),
                ])
                ->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operasionalRoleData(): array
    {
        $opsActivity = (string) ($this->filters['ops_activity'] ?? 'all');
        $opsPrStatus = (string) ($this->filters['ops_pr_status'] ?? 'all');

        $prRows = $this->inRange(
            PurchaseRequest::query()
                ->with(['material:id,nama_material', 'requester:id,name'])
                ->whereNotNull('approved_by_manager_at')
                ->when($opsPrStatus !== 'all', fn (Builder $query) => $query->where('status', $opsPrStatus)),
            'approved_by_manager_at'
        )
            ->latest('approved_by_manager_at')
            ->limit(200)
            ->get();

        $agingTasks = $this->inRange(
            Task::query()
                ->where('type', 'ai_deadstock_recommendation')
                ->whereIn('status', ['pending', 'done', 'rejected']),
            'updated_at'
        )
            ->latest('updated_at')
            ->limit(120)
            ->get();

        $agingRows = [];
        foreach ($agingTasks as $task) {
            $validationStatus = $task->status === 'done'
                ? 'validated'
                : ($task->status === 'rejected'
                    ? 'rejected'
                    : ((string) data_get($task->payload, 'workflow_state', '') === 'approved_for_aging' ? 'pending' : 'draft'));

            foreach ((array) data_get($task->payload, 'deadstock_items', []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $lastUsedDays = (int) ($item['last_used_days'] ?? 0);
                $oldestDays = (int) ($item['oldest_available_days'] ?? 0);
                $kategoriAging = $lastUsedDays >= 120 || $oldestDays >= 120
                    ? 'tinggi'
                    : ($lastUsedDays >= 60 || $oldestDays >= 60 ? 'sedang' : 'rendah');

                $agingRows[] = [
                    'nama_material' => (string) ($item['material_name'] ?? '-'),
                    'kategori_aging' => $kategoriAging,
                    'rekomendasi_ai' => (string) ($item['rekomendasi'] ?? 'monitor'),
                    'status_validasi' => $validationStatus,
                ];
            }
        }

        return [
            'pr_disetujui_manager_ops' => ($opsActivity === 'all' || $opsActivity === 'pr') ? collect($prRows)
                ->map(fn (PurchaseRequest $pr): array => [
                    'id' => (int) $pr->id,
                    'no_pr' => $this->formatPrNumber((int) $pr->id),
                    'material' => (string) ($pr->material?->nama_material ?? '-'),
                    'qty' => (string) number_format((float) $pr->quantity, 2, '.', ''),
                    'diajukan_oleh' => (string) ($pr->requester?->name ?? '-'),
                    'status_lanjut' => (string) $pr->status,
                    'tgl_acc' => $this->formatDateTime($pr->approved_by_manager_at),
                    // Skema PR saat ini 1 material per PR, tetap disiapkan dalam bentuk list untuk kebutuhan expandable row.
                    'items' => [[
                        'nama_material' => (string) ($pr->material?->nama_material ?? '-'),
                        'qty' => (string) number_format((float) $pr->quantity, 2, '.', ''),
                        'satuan' => (string) ($pr->material?->satuan ?? 'unit'),
                    ]],
                ])
                ->all() : [],
            'aging_rekomendasi_ai' => $opsActivity === 'all' || $opsActivity === 'aging' ? $agingRows : [],
            'material_rusak' => ($opsActivity === 'all' || $opsActivity === 'material_rusak') ? Material::query()
                ->where('stok_rusak', '>', 0)
                ->orderByDesc('stok_rusak')
                ->limit(200)
                ->get(['kode_material', 'nama_material', 'kategori', 'stok_rusak'])
                ->map(fn (Material $material): array => [
                    'kode' => (string) ($material->kode_material ?? '-'),
                    'nama_material' => (string) ($material->nama_material ?? '-'),
                    'kategori' => (string) ($material->kategori ?? '-'),
                    'stok_rusak' => (string) number_format((float) ($material->stok_rusak ?? 0), 2, '.', ''),
                ])
                ->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teknikRoleData(): array
    {
        $teknikActivity = (string) ($this->filters['teknik_activity'] ?? 'all');
        $teknikTemplateId = (int) ($this->filters['teknik_template_id'] ?? 0);

        $projects = Project::query()
            ->with([
                'items:id,project_id,material_id,jumlah_diminta',
                'items.material:id,nama_material,satuan',
                'outbounds:id,project_id,created_at,status',
                'outbounds.items:id,outbound_id,material_id,qty,unit',
                'outbounds.items.material:id,nama_material,satuan',
            ])
            ->when($teknikTemplateId > 0, fn (Builder $query) => $query->where('project_template_id', $teknikTemplateId))
            ->latest('created_at')
            ->limit(300)
            ->get();

        $aiBookingTasksByProject = Task::query()
            ->where('type', 'ai_booking_suggestion')
            ->where('related_type', 'project')
            ->orderByDesc('id')
            ->get(['id', 'related_id', 'payload'])
            ->unique('related_id')
            ->keyBy(fn (Task $task): int => (int) ($task->related_id ?? 0));

        $projectIndex = $projects->keyBy('id');

        $projectRows = $projects
            ->map(function (Project $project) use ($aiBookingTasksByProject): array {
                $requestedByMaterial = $project->items
                    ->groupBy('material_id');

                $outboundByMaterial = $project->outbounds
                    ->flatMap(fn (Outbound $outbound): array => $outbound->items->all())
                    ->groupBy('material_id');

                $materialIds = collect($requestedByMaterial->keys())
                    ->concat($outboundByMaterial->keys())
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values();

                $materials = $materialIds
                    ->map(function (int $materialId) use ($requestedByMaterial, $outboundByMaterial): array {
                        $requestedRows = collect($requestedByMaterial->get($materialId, []));
                        $outboundRows = collect($outboundByMaterial->get($materialId, []));

                        $requestedQty = (float) $requestedRows->sum(fn ($item): float => (float) ($item->jumlah_diminta ?? 0));
                        $outboundQty = (float) $outboundRows->sum(fn ($item): float => (float) ($item->qty ?? 0));

                        $requestedFirst = $requestedRows->first();
                        $outboundFirst = $outboundRows->first();

                        return [
                            'nama_material' => (string) ($requestedFirst?->material?->nama_material ?? $outboundFirst?->material?->nama_material ?? '-'),
                            'qty' => (string) number_format(max($requestedQty, $outboundQty), 2, '.', ''),
                            'satuan' => (string) ($requestedFirst?->material?->satuan ?? $outboundFirst?->unit ?? $outboundFirst?->material?->satuan ?? 'unit'),
                        ];
                    })
                    ->values()
                    ->all();

                if (empty($materials)) {
                    /** @var Task|null $aiTask */
                    $aiTask = $aiBookingTasksByProject->get((int) $project->id);

                    if ($aiTask) {
                        $materials = collect((array) data_get($aiTask->payload, 'items', []))
                            ->filter(fn ($item): bool => is_array($item))
                            ->map(fn (array $item): array => [
                                'nama_material' => (string) ($item['material_name'] ?? '-'),
                                'qty' => (string) number_format((float) ($item['dibutuhkan'] ?? $item['recommended_qty'] ?? 0), 2, '.', ''),
                                'satuan' => (string) ($item['satuan'] ?? 'unit'),
                            ])
                            ->values()
                            ->all();
                    }
                }

                return [
                    'project_id' => (int) $project->id,
                    'kode_project' => (string) ($project->kode_proyek ?? '-'),
                    'nama_project' => (string) ($project->nama_proyek ?? '-'),
                    'status' => (string) ($project->status ?? '-'),
                    'tgl_mulai' => $this->formatDate($project->tanggal_mulai),
                    'tgl_selesai' => $this->formatDate($project->tanggal_selesai),
                    'material_count' => count($materials),
                    'materials' => $materials,
                ];
            })
            ->all();

        $takenOutbounds = $this->inRange(
            Outbound::query()
                ->with([
                    'project:id,kode_proyek,nama_proyek',
                    'items:id,outbound_id,material_id,qty,unit',
                    'items.material:id,nama_material,satuan',
                ])
                ->where('status', 'taken')
                ->whereNotNull('project_id')
                ->whereHas('items', fn (Builder $query) => $query->where('qty', '>', 0)),
            'updated_at'
        )
            ->latest('id')
            ->limit(250)
            ->get();

        $groupedOutboundProject = $takenOutbounds
            ->groupBy('project_id')
            ->map(function (Collection $rows, $projectId) use ($projectIndex): array {
                /** @var Project|null $project */
                $project = $projectIndex->get((int) $projectId);
                $projectCode = (string) ($project?->kode_proyek ?? ('#' . (int) $projectId));
                $projectName = (string) ($project?->nama_proyek ?? '-');

                $materialRows = $rows
                    ->flatMap(fn (Outbound $outbound): array => $outbound->items->all())
                    ->groupBy('material_id')
                    ->map(function (Collection $items): array {
                        $first = $items->first();

                        return [
                            'nama_material' => (string) ($first?->material?->nama_material ?? '-'),
                            'qty' => (string) number_format((float) $items->sum(fn ($item): float => (float) ($item->qty ?? 0)), 2, '.', ''),
                            'satuan' => (string) ($first?->unit ?: 'unit'),
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'project_id' => (int) $projectId,
                    'kode_project' => $projectCode,
                    'nama_project' => $projectName,
                    'total_outbound' => (int) $rows->count(),
                    'material_count' => (int) count($materialRows),
                    'materials' => $materialRows,
                ];
            })
            ->values();

        return [
            'daftar_project' => $teknikActivity === 'all' || $teknikActivity === 'project' ? $projectRows : [],
            'outbound_per_project' => $teknikActivity === 'all' || $teknikActivity === 'outbound' ? $groupedOutboundProject->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gudangStaffRoleData(): array
    {
        $gudangActivity = (string) ($this->filters['gudang_activity'] ?? 'all');

        $aiTaskRows = $this->inRange(
            Task::query()
                ->where(function (Builder $query): void {
                    $query->where('type', 'like', 'ai_%');
                })
                ->where('for_role', 'Staff Administrasi Gudang')
                ->whereIn('status', ['pending', 'done', 'rejected']),
            'created_at'
        )
            ->latest('id')
            ->limit(120)
            ->get();

        $aiMatchingRows = [];
        foreach ($aiTaskRows as $task) {
            $rowsAdded = 0;
            $approvalAt = $task->handled_at ?? $task->updated_at;
            $taskStockSnapshot = (float) data_get($task->payload, 'total_available_qty', data_get($task->payload, 'available_qty', 0));

            foreach ((array) data_get($task->payload, 'allocation_suggestions', []) as $suggestion) {
                if (!is_array($suggestion)) {
                    continue;
                }

                $bookingQty = (float) ($suggestion['suggested_qty'] ?? 0);
                $stockAtApproval = $this->resolveTaskStockAtApproval($task, $suggestion, $taskStockSnapshot);
                $rowKey = 'task-' . (int) $task->id . '-alloc-' . $rowsAdded;

                $rowsAdded++;
                $aiMatchingRows[] = [
                    'row_key' => $rowKey,
                    'task_id' => (int) $task->id,
                    'material' => (string) data_get($task->payload, 'material_name', '-'),
                    'project' => (string) ($suggestion['project_code'] ?? data_get($task->payload, 'project_code', '-')),
                    'qty_rekomendasi' => (string) number_format($bookingQty, 2, '.', ''),
                    'rekom_book' => (string) number_format($bookingQty, 2, '.', ''),
                    'rekom_pr' => (string) number_format(0, 2, '.', ''),
                    'stok_aktual' => (string) number_format($stockAtApproval, 2, '.', ''),
                    'stok_saat_acc' => (string) number_format($stockAtApproval, 2, '.', ''),
                    'waktu_acc' => $this->formatDateTime($approvalAt),
                    'alasan_gemini' => $this->extractAiReason($suggestion, $task),
                    'status' => $task->status === 'done' ? 'accepted' : (string) $task->status,
                ];
            }

            foreach ((array) data_get($task->payload, 'items', []) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $bookingQty = (float) ($item['recommended_booking_qty'] ?? $item['qty_booking'] ?? 0);
                $prQty = (float) ($item['recommended_pr_qty'] ?? $item['qty_pr'] ?? 0);
                $stockAtApproval = $this->resolveTaskStockAtApproval($task, $item, $taskStockSnapshot);
                $rowKey = 'task-' . (int) $task->id . '-item-' . $rowsAdded;

                $rowsAdded++;
                $aiMatchingRows[] = [
                    'row_key' => $rowKey,
                    'task_id' => (int) $task->id,
                    'material' => (string) ($item['material_name'] ?? data_get($task->payload, 'material_name', '-')),
                    'project' => (string) data_get($task->payload, 'project_code', '-'),
                    'qty_rekomendasi' => (string) number_format((float) ($item['recommended_qty'] ?? $item['suggested_qty'] ?? $item['qty_booking'] ?? 0), 2, '.', ''),
                    'rekom_book' => (string) number_format($bookingQty, 2, '.', ''),
                    'rekom_pr' => (string) number_format($prQty, 2, '.', ''),
                    'stok_aktual' => (string) number_format($stockAtApproval, 2, '.', ''),
                    'stok_saat_acc' => (string) number_format($stockAtApproval, 2, '.', ''),
                    'waktu_acc' => $this->formatDateTime($approvalAt),
                    'alasan_gemini' => $this->extractAiReason($item, $task),
                    'status' => $task->status === 'done' ? 'accepted' : (string) $task->status,
                ];
            }

            if ($rowsAdded === 0) {
                $aiMatchingRows[] = [
                    'row_key' => 'task-' . (int) $task->id . '-fallback',
                    'task_id' => (int) $task->id,
                    'material' => (string) data_get($task->payload, 'material_name', '-'),
                    'project' => (string) data_get($task->payload, 'project_code', '-'),
                    'qty_rekomendasi' => '-',
                    'rekom_book' => '-',
                    'rekom_pr' => '-',
                    'stok_aktual' => '-',
                    'stok_saat_acc' => '-',
                    'waktu_acc' => $this->formatDateTime($approvalAt),
                    'alasan_gemini' => $this->extractAiReason(null, $task),
                    'status' => $task->status === 'done' ? 'accepted' : (string) $task->status,
                ];
            }
        }

        $manualInboundRows = $this->inRange(
            Inbound::query()
                ->with(['material:id,nama_material', 'project:id,kode_proyek', 'project.items:id,project_id,jumlah_diminta'])
                ->whereNull('purchase_order_id'),
            'created_at'
        )
            ->latest('created_at')
            ->limit(120)
            ->get()
            ->map(fn (Inbound $inbound): array => [
                'id' => (int) $inbound->id,
                'tipe' => 'inbound_manual',
                'judul' => 'Inbound Manual - ' . (string) ($inbound->material?->nama_material ?? '-'),
                'untuk' => (string) ($inbound->project?->kode_proyek ?? 'Staff Administrasi Gudang'),
                'kebutuhan' => (string) number_format((float) ($inbound->project?->items?->sum('jumlah_diminta') ?? $inbound->quantity ?? 0), 2, '.', ''),
                'alokasi' => (string) number_format((float) ($inbound->quantity ?? 0), 2, '.', ''),
                'status' => 'accepted',
                'dibuat' => $this->formatDateTime($inbound->created_at),
                'sort_at' => Carbon::parse($inbound->created_at)->timestamp,
            ]);

        $manualAllocationRows = $this->inRange(
            Outbound::query()
                ->with(['project:id,kode_proyek', 'project.items:id,project_id,jumlah_diminta', 'items:id,outbound_id,qty'])
                ->where('outbound_manual', true),
            'created_at'
        )
            ->latest('created_at')
            ->limit(120)
            ->get()
            ->map(fn (Outbound $outbound): array => [
                'id' => (int) $outbound->id,
                'tipe' => 'alokasi_manual',
                'judul' => 'Alokasi Manual - ' . (string) ($outbound->project?->kode_proyek ?? '-'),
                'untuk' => (string) ($outbound->project?->kode_proyek ?? '-'),
                'kebutuhan' => (string) number_format((float) $outbound->project?->items?->sum('jumlah_diminta'), 2, '.', ''),
                'alokasi' => (string) number_format((float) $outbound->items->sum('qty'), 2, '.', ''),
                'status' => $outbound->status === 'taken' ? 'accepted' : (string) ($outbound->status ?? '-'),
                'dibuat' => $this->formatDateTime($outbound->created_at),
                'sort_at' => Carbon::parse($outbound->created_at)->timestamp,
            ]);

        $taskOperasionalRows = $manualInboundRows
            ->concat($manualAllocationRows)
            ->sortByDesc('sort_at')
            ->values()
            ->map(function (array $row): array {
                if (!array_key_exists('kebutuhan', $row)) {
                    $row['kebutuhan'] = '-';
                }
                if (!array_key_exists('alokasi', $row)) {
                    $row['alokasi'] = '-';
                }
                unset($row['sort_at']);

                return $row;
            })
            ->all();

        $outboundRows = $this->inRange(
            Outbound::query()
                ->with([
                    'project:id,kode_proyek',
                    'project.items:id,project_id,material_id,jumlah_diminta',
                    'project.items.material:id,nama_material,satuan',
                    'items:id,outbound_id,material_id,qty,unit',
                    'items.material:id,nama_material',
                ]),
            'created_at'
        )
            ->latest('created_at')
            ->limit(250)
            ->get()
            ->map(function (Outbound $outbound): array {
                $materialItems = $outbound->items
                    ->map(function ($item): array {
                        return [
                            'nama_material' => (string) ($item->material?->nama_material ?? '-'),
                            'qty' => (string) number_format((float) ($item->qty ?? 0), 2, '.', ''),
                            'satuan' => (string) ($item->unit ?: 'unit'),
                        ];
                    })
                    ->values()
                    ->all();

                if (empty($materialItems) && $outbound->project) {
                    $materialItems = $outbound->project->items
                        ->map(fn (ProjectItem $item): array => [
                            'nama_material' => (string) ($item->material?->nama_material ?? '-'),
                            'qty' => (string) number_format((float) ($item->jumlah_diminta ?? 0), 2, '.', ''),
                            'satuan' => (string) ($item->material?->satuan ?? 'unit'),
                        ])
                        ->values()
                        ->all();
                }

                return [
                    'id' => (int) $outbound->id,
                    'tgl' => $this->formatDateTime($outbound->created_at),
                    'project' => (string) ($outbound->project?->kode_proyek ?? '-'),
                    'status' => (string) ($outbound->status ?? '-'),
                    'material_count' => count($materialItems),
                    'material_items' => $materialItems,
                ];
            })
            ->all();

        $stokRows = Material::query()
            ->orderBy('nama_material')
            ->limit(400)
            ->get(['kode_material', 'nama_material', 'kategori', 'stok', 'min_stok', 'satuan'])
            ->map(function (Material $material): array {
                $stok = (float) ($material->stok ?? 0);
                $minimum = (float) ($material->min_stok ?? 0);

                $level = 'normal';
                if ($minimum > 0 && $stok <= $minimum) {
                    $level = 'critical';
                } elseif ($minimum > 0 && $stok <= ($minimum * 1.5)) {
                    $level = 'warning';
                }

                return [
                    'nama_material' => (string) ($material->nama_material ?? '-'),
                    'kode' => (string) ($material->kode_material ?? '-'),
                    'kategori' => (string) ($material->kategori ?? '-'),
                    'stok' => (string) number_format($stok, 2, '.', ''),
                    'stok_minimum' => (string) number_format($minimum, 2, '.', ''),
                    'satuan' => (string) ($material->satuan ?? 'unit'),
                    'level' => $level,
                ];
            })
            ->all();

        $inboundRows = $this->inRange(
            Inbound::query()->with(['material:id,nama_material']),
            'created_at'
        )
            ->latest('created_at')
            ->limit(300)
            ->get()
            ->map(fn (Inbound $inbound): array => [
                'tgl' => $this->formatDateTime($inbound->created_at),
                'no_po' => (string) ($inbound->purchaseOrder?->po_number ?? '-'),
                'material' => (string) ($inbound->material?->nama_material ?? '-'),
                'qty' => (string) number_format((float) ($inbound->quantity ?? 0), 2, '.', ''),
                'sumber' => $inbound->purchase_order_id ? 'PO' : 'Manual',
            ])
            ->all();

        $bookingRows = $this->inRange(
            MaterialPiece::query()
                ->with(['material:id,nama_material', 'bookedForProject:id,kode_proyek'])
                ->where('status', 'booked')
                ->whereNotNull('booked_at'),
            'booked_at'
        )
            ->latest('booked_at')
            ->limit(300)
            ->get()
            ->map(fn (MaterialPiece $piece): array => [
                'material' => (string) ($piece->material?->nama_material ?? '-'),
                'project' => (string) ($piece->bookedForProject?->kode_proyek ?? '-'),
                'qty_booked' => (string) number_format((float) ($piece->panjang ?? 0), 2, '.', ''),
                'tgl_booking' => $this->formatDateTime($piece->booked_at),
            ])
            ->all();

        $returRows = $this->inRange(
            Inbound::query()
                ->with('material:id,nama_material')
                ->where('jenis_inbound', 'Retur'),
            'created_at'
        )
            ->latest('created_at')
            ->limit(300)
            ->get()
            ->map(fn (Inbound $inbound): array => [
                'tgl' => $this->formatDateTime($inbound->created_at),
                'material' => (string) ($inbound->material?->nama_material ?? '-'),
                'qty' => (string) number_format((float) ($inbound->quantity ?? 0), 2, '.', ''),
                'keterangan' => (string) ($inbound->keterangan ?? '-'),
            ])
            ->all();

        return [
            'rekomendasi_ai_matching' => $gudangActivity === 'all' || $gudangActivity === 'ai_matching' ? $aiMatchingRows : [],
            'task_operasional' => $gudangActivity === 'all' || $gudangActivity === 'task_operasional' ? $taskOperasionalRows : [],
            'laporan_stok_gudang' => $gudangActivity === 'all' || $gudangActivity === 'stok' ? $stokRows : [],
            'outbound' => $gudangActivity === 'all' || $gudangActivity === 'outbound' ? $outboundRows : [],
            'inbound' => $gudangActivity === 'all' || $gudangActivity === 'inbound' ? $inboundRows : [],
            'booking_aktif' => $gudangActivity === 'all' || $gudangActivity === 'booking' ? $bookingRows : [],
            'retur' => $gudangActivity === 'all' || $gudangActivity === 'retur' ? $returRows : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allRoleData(): array
    {
        return [
            'finance' => $this->financeRoleData(),
            'manager_operasional' => $this->operasionalRoleData(),
            'manager_teknik' => $this->teknikRoleData(),
            'staff_administrasi_gudang' => $this->gudangStaffRoleData(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRoleReportData(): array
    {
        if ($this->canViewFinanceSection() && !$this->canViewOperasionalSection() && !$this->canViewTeknikSection() && !$this->canViewGudangSection()) {
            return ['finance' => $this->financeRoleData()];
        }

        if ($this->canViewOperasionalSection() && !$this->canViewFinanceSection() && !$this->canViewTeknikSection() && !$this->canViewGudangSection()) {
            return ['manager_operasional' => $this->operasionalRoleData()];
        }

        if ($this->canViewTeknikSection() && !$this->canViewFinanceSection() && !$this->canViewOperasionalSection() && !$this->canViewGudangSection()) {
            return ['manager_teknik' => $this->teknikRoleData()];
        }

        if ($this->canViewGudangSection() && !$this->canViewFinanceSection() && !$this->canViewOperasionalSection() && !$this->canViewTeknikSection()) {
            return ['staff_administrasi_gudang' => $this->gudangStaffRoleData()];
        }

        return $this->allRoleData();
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function buildExportRows(): array
    {
        $rows = [];

        if ($this->canViewFinanceSection()) {
            foreach ($this->financeStats() as $metric => $value) {
                $rows[] = ['Finance', (string) $metric, (string) $value];
            }
        }

        if ($this->canViewOperasionalSection()) {
            foreach ($this->operasionalStats() as $metric => $value) {
                $rows[] = ['Manager Operasional', (string) $metric, (string) $value];
            }
        }

        if ($this->canViewTeknikSection()) {
            foreach ($this->teknikStats() as $metric => $value) {
                $rows[] = ['Manager Teknik', (string) $metric, (string) $value];
            }
        }

        if ($this->canViewGudangSection()) {
            foreach ($this->gudangStats() as $metric => $value) {
                $rows[] = ['Gudang', (string) $metric, (string) $value];
            }
        }

        return [
            ['Section', 'Metric', 'Value'],
            $rows,
        ];
    }

    private function inRange(Builder $query, string $column): Builder
    {
        if ($this->dateFrom !== '') {
            $query->whereDate($column, '>=', $this->dateFrom);
        }

        if ($this->dateTo !== '') {
            $query->whereDate($column, '<=', $this->dateTo);
        }

        return $query;
    }

    private function isCurrentRole(string $key): bool
    {
        $role = mb_strtolower(trim((string) auth()->user()?->role));

        return match ($key) {
            'finance' => in_array($role, ['finance', 'keuangan'], true),
            'operasional' => in_array($role, ['manager operasional', 'manager operational', 'manajer operasional'], true),
            'teknik' => in_array($role, ['manager teknik', 'manajer teknik'], true),
            'gudang' => in_array($role, ['staff administrasi gudang', 'staff adm gudang', 'staff gudang', 'admin gudang', 'administrator gudang'], true),
            'gudang_manager' => in_array($role, ['manager gudang', 'manajer gudang'], true),
            'admin' => in_array($role, ['administrator it', 'admin it'], true),
            default => false,
        };
    }

    private function logCctv(string $action, string $description): void
    {
        if (!auth()->check()) {
            return;
        }

        UserLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => 'Laporan',
            'description' => $description,
            'ip_address' => request()?->ip(),
        ]);
    }

    private function formatDateTime($value): string
    {
        if (blank($value)) {
            return '-';
        }

        return Carbon::parse($value)->format('d M Y H:i');
    }

    private function formatDate($value): string
    {
        if (blank($value)) {
            return '-';
        }

        return Carbon::parse($value)->format('d M Y');
    }

    private function formatPrNumber(int $id): string
    {
        return 'PR-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    private function extractAiReason(?array $row, Task $task): string
    {
        $candidates = [
            data_get($row, 'ai_reason'),
            data_get($row, 'reason'),
            data_get($row, 'alasan_ai'),
            data_get($row, 'reasoning'),
            data_get($row, 'explanation'),
            data_get($task->payload, 'ai_reasoning'),
            data_get($task->payload, 'ai_summary.reason'),
            data_get($task->payload, 'summary.reason'),
            data_get($task->payload, 'reason'),
        ];

        foreach ($candidates as $reason) {
            $value = trim((string) $reason);
            if ($value !== '') {
                return $value;
            }
        }

        return '-';
    }

    private function resolveTaskStockAtApproval(Task $task, ?array $row, float $fallback): float
    {
        $materialId = (int) data_get($row, 'material_id', 0);

        $candidates = [
            data_get($row, 'stock_at_approval'),
            data_get($row, 'available_qty'),
            data_get($row, 'available'),
            data_get($task->payload, 'items.' . $this->findTaskItemIndexByMaterial($task, $materialId) . '.stock_at_approval'),
            data_get($task->payload, 'items.' . $this->findTaskItemIndexByMaterial($task, $materialId) . '.available_qty'),
            data_get($task->payload, 'detail.' . $this->findTaskDetailIndexByMaterial($task, $materialId) . '.available'),
            data_get($task->payload, 'total_available_qty'),
            $fallback,
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            return round((float) $value, 2);
        }

        return 0.0;
    }

    private function findTaskItemIndexByMaterial(Task $task, int $materialId): int
    {
        if ($materialId <= 0) {
            return -1;
        }

        foreach ((array) data_get($task->payload, 'items', []) as $index => $item) {
            if ((int) data_get($item, 'material_id', 0) === $materialId) {
                return (int) $index;
            }
        }

        return -1;
    }

    private function findTaskDetailIndexByMaterial(Task $task, int $materialId): int
    {
        if ($materialId <= 0) {
            return -1;
        }

        foreach ((array) data_get($task->payload, 'detail', []) as $index => $item) {
            if ((int) data_get($item, 'material_id', 0) === $materialId) {
                return (int) $index;
            }
        }

        return -1;
    }
}
