<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Task;
use App\Models\User;
use App\Models\UserLog;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class LaporanDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan Operasional';

    protected string $view = 'filament.pages.laporan-dashboard';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $module = '';

    public string $actionFilter = '';

    public string $userRole = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->dateFrom = (string) request()->query('date_from', now()->startOfMonth()->toDateString());
        $this->dateTo = (string) request()->query('date_to', now()->toDateString());
        $this->module = trim((string) request()->query('module', ''));
        $this->actionFilter = trim((string) request()->query('action', ''));
        $this->userRole = trim((string) request()->query('user_role', ''));

        $this->rows = $this->loadRows();
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
        $this->rows = $this->loadRows();

        $this->logCctv(
            'Filter Laporan',
            'Menerapkan filter laporan. date_from=' . ($this->dateFrom ?: '-')
                . ', date_to=' . ($this->dateTo ?: '-')
                . ', module=' . ($this->module ?: '-')
                . ', action=' . ($this->actionFilter ?: '-')
                . ', user_role=' . ($this->userRole ?: '-')
        );
    }

    /**
     * @return array<int, string>
     */
    public function moduleOptions(): array
    {
        return UserLog::query()
            ->select('module')
            ->whereNotNull('module')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function actionOptions(): array
    {
        return UserLog::query()
            ->select('action')
            ->whereNotNull('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function roleOptions(): array
    {
        return UserLog::query()
            ->join('users', 'users.id', '=', 'user_logs.user_id')
            ->select('users.role')
            ->whereNotNull('users.role')
            ->distinct()
            ->orderBy('users.role')
            ->pluck('users.role')
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
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
        return $this->isCurrentRole('finance') || $this->isCurrentRole('admin') || $this->isCurrentRole('gudang_manager');
    }

    public function canViewOperasionalSection(): bool
    {
        return $this->isCurrentRole('operasional') || $this->isCurrentRole('admin') || $this->isCurrentRole('gudang_manager');
    }

    public function canViewTeknikSection(): bool
    {
        return $this->isCurrentRole('teknik') || $this->isCurrentRole('admin') || $this->isCurrentRole('gudang_manager');
    }

    public function canViewGudangSection(): bool
    {
        return $this->isCurrentRole('gudang') || $this->isCurrentRole('admin') || $this->isCurrentRole('gudang_manager');
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function actorAccessRows(): array
    {
        return User::query()
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(function (User $user): array {
                $modules = $this->modulesForRole((string) $user->role);

                return [
                    'name' => (string) ($user->name ?: '-'),
                    'email' => (string) ($user->email ?: '-'),
                    'role' => (string) ($user->role ?: '-'),
                    'modules' => empty($modules) ? '-' : implode(', ', $modules),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function roleAccessRows(): array
    {
        $roles = User::query()
            ->whereNull('deleted_at')
            ->whereNotNull('role')
            ->distinct()
            ->orderBy('role')
            ->pluck('role')
            ->map(fn ($role): string => (string) $role)
            ->values();

        return $roles
            ->map(function (string $role): array {
                $modules = $this->modulesForRole($role);

                return [
                    'role' => $role,
                    'modules' => empty($modules) ? '-' : implode(', ', $modules),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(): array
    {
        return $this->filteredLogQuery()
            ->limit(300)
            ->get(['id', 'user_id', 'action', 'module', 'description', 'ip_address', 'created_at'])
            ->map(function (UserLog $log): array {
                return [
                    'id' => (int) $log->id,
                    'user_name' => (string) ($log->user?->name ?? '-'),
                    'user_role' => (string) ($log->user?->role ?? '-'),
                    'module' => (string) ($log->module ?? '-'),
                    'action' => (string) ($log->action ?? '-'),
                    'description' => (string) ($log->description ?? '-'),
                    'ip_address' => (string) ($log->ip_address ?? '-'),
                    'created_at' => optional($log->created_at)?->format('d M Y H:i') ?: '-',
                ];
            })
            ->all();
    }

    private function filteredLogQuery(): Builder
    {
        $query = UserLog::query()->with('user:id,name,role');
        $this->inRange($query, 'created_at');

        if ($this->module !== '') {
            $query->where('module', $this->module);
        }

        if ($this->actionFilter !== '') {
            $query->where('action', $this->actionFilter);
        }

        if ($this->userRole !== '') {
            $query->whereHas('user', fn (Builder $q) => $q->where('role', $this->userRole));
        }

        return $query->orderByDesc('id');
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

    /**
     * @return array<int, string>
     */
    private function modulesForRole(string $role): array
    {
        $normalized = mb_strtolower(trim($role));

        return match ($normalized) {
            'administrator it', 'admin it' => [
                'Dashboard',
                'Projects',
                'Tasks',
                'Aging Material',
                'Purchase Request',
                'Purchase Orders',
                'Laporan',
                'Materials',
                'Project Templates',
                'Shelf Locations',
                'Suppliers',
                'Inbound',
                'Outbound',
            ],
            'manager operasional', 'manager operational', 'manajer operasional' => [
                'Dashboard',
                'Projects',
                'Tasks (AI)',
                'Aging Material',
                'Purchase Request',
                'Purchase Orders',
                'Laporan',
            ],
            'finance', 'keuangan' => [
                'Dashboard',
                'Purchase Request',
                'Purchase Orders',
                'Aging Material (view only)',
                'Laporan',
            ],
            'manager teknik', 'manajer teknik' => [
                'Dashboard',
                'Projects',
                'Tasks',
                'Project Templates',
                'Outbound',
                'Laporan',
            ],
            'manager gudang', 'manajer gudang' => [
                'Dashboard',
                'Materials',
                'Inbound',
                'Outbound',
                'Purchase Request',
                'Purchase Orders',
                'Laporan',
            ],
            'staff administrasi gudang', 'staff adm gudang', 'staff gudang', 'admin gudang', 'administrator gudang' => [
                'Dashboard',
                'Projects',
                'Tasks',
                'Purchase Request',
                'Purchase Orders',
                'Materials',
                'Project Templates',
                'Shelf Locations',
                'Suppliers',
                'Inbound',
                'Outbound',
                'Laporan',
            ],
            default => [],
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
}
