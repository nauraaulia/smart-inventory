<?php

namespace App\Models;

use Filament\Notifications\Notification;
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
        if (auth()->check()) {
            \App\Models\UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'Project',
                'description' => 'Membuat proyek baru: ' . $item->kode_proyek . ' - ' . $item->nama_proyek,
                'ip_address' => request()->ip(),
            ]);
        }

        Task::create([
            'for_role' => 'Staff Administrasi Gudang',
            'type' => 'project_created_notification',
            'title' => "Proyek Baru {$item->kode_proyek}",
            'message' => "Project {$item->kode_proyek} ({$item->nama_proyek}) sudah dibuat dan siap diproses tim gudang.",
            'payload' => [
                'project_id' => $item->id,
                'project_code' => $item->kode_proyek,
                'project_name' => $item->nama_proyek,
                'created_by' => auth()->id(),
            ],
            'status' => 'pending',
            'related_id' => $item->id,
            'related_type' => 'project',
        ]);

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

        // 🔥 TRIGGER AI: jalankan setelah response terkirim.
        // Aman untuk local tanpa queue worker, dan memberi waktu relasi items tersimpan dulu.
        \App\Jobs\ProsesKebutuhanProyekJob::dispatchAfterResponse($item->id);
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

        if (!empty($this->material_confirmed_at)) {
            return 'Barang Sudah Lengkap';
        }

        return match ((string) $this->status) {
            'Processing' => 'Sedang Diproses Gudang',
            'Approved' => 'Menunggu Proses Gudang',
            'Completed' => 'Selesai',
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
            'Barang Sudah Lengkap' => 'info',
            'Sedang Diproses Gudang' => 'warning',
            'Menunggu Proses Gudang' => 'gray',
            'Selesai' => 'success',
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
            'Barang Sudah Lengkap' => 'Material sudah terkonfirmasi lengkap. Menunggu outbound/penyerahan.',
            'Sedang Diproses Gudang' => 'Gudang sedang menyiapkan material sesuai kebutuhan proyek.',
            'Menunggu Proses Gudang' => 'Approval sudah selesai, menunggu proses pemenuhan oleh gudang.',
            'Selesai' => 'Proyek selesai diproses.',
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



    
}