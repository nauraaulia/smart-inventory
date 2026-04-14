<?php

namespace App\Models;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\DatabaseNotification as DatabaseNotificationModel;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $guarded = [];

    protected $appends = [
        'progress_label',
        'progress_color',
        'progress_description',
    ];

    // =========================================
    // 🔑 AUTO GENERATE KODE PROYEK
    // =========================================
    public static function generateKodeProyek(int $templateId): string
    {
        $template = ProjectTemplate::findOrFail($templateId);

        $kategori = $template->kode_kategori;
        $periode  = now()->format('ym');

        $prefix = "PRJ-{$kategori}-{$periode}";

        $lastProject = self::where('kode_proyek', 'like', "{$prefix}-%")
            ->orderByDesc('kode_proyek')
            ->first();

        if ($lastProject) {
            $lastNumber = (int) substr($lastProject->kode_proyek, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('%s-%04d', $prefix, $nextNumber);
    }

    // =========================================
    // 📹 CCTV USER LOG
    // =========================================
    protected static function booted()
{
    // 1. CCTV Saat Proyek Dibuat (Create)
    static::created(function ($item) {
        $creatorRole = (string) (auth()->user()?->role ?? '');
        $isManagerTeknikCreator = in_array($creatorRole, ['Manager Teknik', 'Manajer Teknik'], true);

        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'Project',
                'description' => 'Membuat proyek baru: ' . $item->kode_proyek . ' - ' . $item->nama_proyek,
                'ip_address' => request()->ip(),
            ]);
        }

        if ($isManagerTeknikCreator) {
            $gudangUsers = User::query()
                ->whereIn('role', ['Staff Administrasi Gudang', 'Staff Adm Gudang', 'Staff Gudang'])
                ->get();

            if ($gudangUsers->isNotEmpty()) {
                $databaseMessage = Notification::make()
                    ->title("Proyek baru {$item->kode_proyek}")
                    ->body("{$item->nama_proyek} sudah dibuat Manager Teknik dan siap diproses gudang.")
                    ->success()
                    ->getDatabaseMessage();

                foreach ($gudangUsers as $gudangUser) {
                    DatabaseNotificationModel::query()->create([
                        'id' => (string) Str::uuid(),
                        'type' => \Filament\Notifications\DatabaseNotification::class,
                        'notifiable_type' => User::class,
                        'notifiable_id' => $gudangUser->id,
                        'data' => $databaseMessage,
                        'read_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // 🔥 TRIGGER AI: masukkan ke queue agar retry policy berlaku.
        \App\Jobs\ProsesKebutuhanProyekJob::dispatch($item->id, $isManagerTeknikCreator);
    });

    // 2. CCTV Saat Proyek Diedit (Update)
    static::updated(function ($item) {
        if (auth()->check()) {
            $changes = $item->getChanges();
            $detailPerubahan = [];

            foreach ($changes as $kolom => $nilaiBaru) {
                if ($kolom !== 'updated_at') {
                    $nilaiLama = $item->getOriginal($kolom);
                    $detailPerubahan[] = "{$kolom} (dari '{$nilaiLama}' ke '{$nilaiBaru}')";
                }
            }

            $teksPerubahan = implode(', ', $detailPerubahan);

            \App\Models\UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update',
                'module' => 'Project',
                'description' => 'Mengubah proyek ' . $item->kode_proyek . ' : ' . $teksPerubahan,
                'ip_address' => request()->ip(),
            ]);
        }

        // Trigger AI booking tidak dijalankan dari update Project untuk menghindari duplicate burst.
    });

    // 3. CCTV Saat Proyek Dihapus (Delete)
    static::deleted(function ($item) {
        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => 'Project',
                'description' => 'Menghapus proyek: ' . $item->kode_proyek . ' - ' . $item->nama_proyek,
                'ip_address' => request()->ip(),
            ]);
        }
    });
}

    // =========================================
    // RELATIONSHIPS
    // =========================================
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProjectItem::class);
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    public function bookedPieces(): HasMany
    {
        return $this->hasMany(MaterialPiece::class, 'booked_for_project_id');
    }

    public function outbounds(): HasMany
    {
        return $this->hasMany(Outbound::class);
    }

    public function scopeCategoryPerencanaan(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $outer): void {
                $outer
                    ->where(function (Builder $q): void {
                        $q->whereNull('material_confirmed_at')
                            ->whereNotExists(function ($sub): void {
                                $sub->selectRaw('1')
                                    ->from('project_items as pi_exists')
                                    ->whereColumn('pi_exists.project_id', 'projects.id');
                            });
                    })
                    ->orWhere(function (Builder $q): void {
                        $q->whereNull('material_confirmed_at')
                            ->whereExists(function ($sub): void {
                                $sub->selectRaw('1')
                                    ->from('project_items as pi_exists')
                                    ->whereColumn('pi_exists.project_id', 'projects.id');
                            })
                            ->whereRaw("EXISTS (
                                SELECT 1
                                FROM (
                                    SELECT pi.material_id, SUM(pi.jumlah_diminta) AS total_required
                                    FROM project_items pi
                                    WHERE pi.project_id = projects.id
                                    GROUP BY pi.material_id
                                ) req
                                LEFT JOIN (
                                    SELECT mp.material_id, SUM(mp.panjang) AS total_booked
                                    FROM material_pieces mp
                                    WHERE mp.booked_for_project_id = projects.id
                                      AND mp.status = 'booked'
                                    GROUP BY mp.material_id
                                ) booked ON booked.material_id = req.material_id
                                WHERE req.total_required > COALESCE(booked.total_booked, 0) + 0.00001
                            )");
                    });
            })
            ->whereNull('outbound_confirmed_at')
            ->where(function (Builder $q): void {
                $q->whereNull('completed_at')
                    ->where('status', '!=', 'Completed');
            });
    }

    public function scopeCategoryBarangLengkap(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q): void {
                $q->whereNotNull('material_confirmed_at')
                    ->orWhereRaw("(
                        EXISTS (
                            SELECT 1
                            FROM project_items pi_exists
                            WHERE pi_exists.project_id = projects.id
                        )
                        AND NOT EXISTS (
                            SELECT 1
                            FROM (
                                SELECT pi.material_id, SUM(pi.jumlah_diminta) AS total_required
                                FROM project_items pi
                                WHERE pi.project_id = projects.id
                                GROUP BY pi.material_id
                            ) req
                            LEFT JOIN (
                                SELECT mp.material_id, SUM(mp.panjang) AS total_booked
                                FROM material_pieces mp
                                WHERE mp.booked_for_project_id = projects.id
                                  AND mp.status = 'booked'
                                GROUP BY mp.material_id
                            ) booked ON booked.material_id = req.material_id
                            WHERE req.total_required > COALESCE(booked.total_booked, 0) + 0.00001
                        )
                    )");
            })
            ->whereNull('outbound_confirmed_at')
            ->where(function (Builder $q): void {
                $q->whereNull('completed_at')
                    ->where('status', '!=', 'Completed');
            });
    }

    public function scopeCategorySiapDiambil(Builder $query): Builder
    {
        return $query
            ->whereNotNull('outbound_confirmed_at')
            ->where(function (Builder $q): void {
                $q->whereNull('completed_at')
                    ->where('status', '!=', 'Completed');
            });
    }

    public function scopeCategoryComplete(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q): void {
                $q->where('status', 'Completed')
                    ->orWhereNotNull('completed_at');
            });
    }

    public function getProjectCategoryAttribute(): string
    {
        if ($this->status === 'Completed' || !empty($this->completed_at)) {
            return 'complete';
        }

        if (!empty($this->outbound_confirmed_at)) {
            return 'siap_diambil';
        }

        if (!empty($this->material_confirmed_at) || $this->outbound_is_complete) {
            return 'barang_lengkap';
        }

        return 'perencanaan';
    }

    public function getProjectCategoryLabelAttribute(): string
    {
        return match ($this->project_category) {
            'barang_lengkap' => 'Barang Lengkap',
            'siap_diambil' => 'Siap Diambil',
            'complete' => 'Complete',
            default => 'Perencanaan Kebutuhan',
        };
    }

    public function getProjectCategoryColorAttribute(): string
    {
        return match ($this->project_category) {
            'barang_lengkap' => 'info',
            'siap_diambil' => 'success',
            'complete' => 'success',
            default => 'gray',
        };
    }

    public function getProgressLabelAttribute(): string
    {
        if ($this->prCount('pending_manager') > 0) {
            return 'Menunggu Approval Manager';
        }

        if ($this->prCount('pending_finance') > 0) {
            return 'Menunggu Approval Finance';
        }

        if ($this->prCount('draft') > 0) {
            return 'Menunggu Review Draft PR';
        }

        if ($this->prCount('rejected') > 0) {
            return 'Perlu Revisi PR';
        }

        if (!empty($this->outbound_confirmed_at)) {
            return 'Siap Diambil';
        }

        if ($this->outbound_is_complete) {
            return 'Barang Lengkap';
        }

        if (!empty($this->material_confirmed_at)) {
            return 'Barang Lengkap';
        }

        return match ((string) $this->status) {
            'Processing' => 'Sedang Diproses Gudang',
            'Approved' => 'Menunggu Proses Gudang',
            'Completed' => 'Complete',
            default => 'Perencanaan Kebutuhan',
        };
    }

    public function getProgressColorAttribute(): string
    {
        return match ($this->progress_label) {
            'Menunggu Approval Manager' => 'warning',
            'Menunggu Approval Finance' => 'warning',
            'Menunggu Review Draft PR' => 'gray',
            'Perlu Revisi PR' => 'danger',
            'Siap Diambil' => 'success',
            'Barang Lengkap' => 'info',
            'Sedang Diproses Gudang' => 'warning',
            'Menunggu Proses Gudang' => 'gray',
            'Complete' => 'success',
            default => 'gray',
        };
    }

    public function getProgressDescriptionAttribute(): string
    {
        return match ($this->progress_label) {
            'Menunggu Approval Manager' => 'Draft PR sudah dikirim dan menunggu persetujuan Manager Operasional.',
            'Menunggu Approval Finance' => 'PR sudah disetujui Manager Operasional dan menunggu approval Finance.',
            'Menunggu Review Draft PR' => 'Draft PR masih di tahap review internal staff gudang sebelum dikirim ke Manager.',
            'Perlu Revisi PR' => 'PR ditolak dan perlu revisi dari staff gudang sebelum diajukan ulang.',
            'Siap Diambil' => 'Semua kebutuhan sudah diproses dan outbound sudah dikonfirmasi.',
            'Barang Lengkap' => 'Material sudah terkonfirmasi lengkap. Menunggu outbound/penyerahan.',
            'Sedang Diproses Gudang' => 'Gudang sedang menyiapkan material sesuai kebutuhan proyek.',
            'Menunggu Proses Gudang' => 'Approval sudah selesai, menunggu proses pemenuhan oleh gudang.',
            'Complete' => 'Proyek selesai diproses.',
            default => 'Kebutuhan material sedang dianalisis dan menunggu proses berikutnya.',
        };
    }

    private function prCount(string $status): int
    {
        $attribute = 'pr_' . $status . '_count';

        if (array_key_exists($attribute, $this->attributes)) {
            return (int) $this->attributes[$attribute];
        }

        return $this->purchaseRequests()->where('status', $status)->count();
    }

    public function getOutboundIsCompleteAttribute(): bool
    {
        $requiredByMaterial = $this->relationLoaded('items')
            ? $this->items
                ->groupBy('material_id')
                ->map(fn ($items) => (float) $items->sum('jumlah_diminta'))
            : $this->items()
                ->selectRaw('material_id, SUM(jumlah_diminta) as total_required')
                ->groupBy('material_id')
                ->pluck('total_required', 'material_id')
                ->map(fn ($value) => (float) $value);

        if ($requiredByMaterial->isEmpty()) {
            return false;
        }

        $bookedByMaterial = $this->relationLoaded('bookedPieces')
            ? $this->bookedPieces
                ->where('status', 'booked')
                ->groupBy('material_id')
                ->map(fn ($pieces) => (float) $pieces->sum('panjang'))
            : $this->bookedPieces()
                ->where('status', 'booked')
                ->selectRaw('material_id, SUM(panjang) as total_booked')
                ->groupBy('material_id')
                ->pluck('total_booked', 'material_id')
                ->map(fn ($value) => (float) $value);

        foreach ($requiredByMaterial as $materialId => $requiredQty) {
            if ($requiredQty <= 0) {
                continue;
            }

            $bookedQty = (float) ($bookedByMaterial[(int) $materialId] ?? 0);

            if ($bookedQty + 0.00001 < $requiredQty) {
                return false;
            }
        }

        return true;
    }



    
}