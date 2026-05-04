<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_GENERAL_MANAGER = 'General Manager';

    public static function roleOptions(): array
    {
        return [
            'Administrator IT' => 'Administrator IT',
            self::ROLE_GENERAL_MANAGER => self::ROLE_GENERAL_MANAGER,
            'Manager Teknik' => 'Manager Teknik',
            'Staff Administrasi Gudang' => 'Staff Administrasi Gudang',
            'Finance' => 'Finance',
            'Manager Operasional' => 'Manager Operasional',
        ];
    }

    public static function isGeneralManagerRole(?string $role): bool
    {
        return mb_strtolower(trim((string) $role)) === mb_strtolower(self::ROLE_GENERAL_MANAGER);
    }

    public static function nextCustomId(): string
    {
        $lastNumber = self::withTrashed()
            ->pluck('id_custom')
            ->map(function (?string $idCustom): int {
                if (! preg_match('/^JAU-(\d+)$/', (string) $idCustom, $matches)) {
                    return 0;
                }

                return (int) $matches[1];
            })
            ->max() ?? 0;

        return 'JAU-'.str_pad((string) ($lastNumber + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pin',
        'id_custom',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'pin' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (blank($user->id_custom)) {
                $user->updateQuietly([
                    'id_custom' => self::nextCustomId(),
                ]);
            }

            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Create',
                'module' => 'User',
                'description' => 'Membuat user '.(string) ($user->id_custom ?: ('#'.$user->id)).' ('.(string) ($user->name ?? '-').')',
                'ip_address' => request()?->ip(),
            ]);
        });

        static::updated(function (User $user): void {
            if (! auth()->check() || empty($user->getChanges())) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Update',
                'module' => 'User',
                'description' => 'Update user '.(string) ($user->id_custom ?: ('#'.$user->id)).' ('.(string) ($user->name ?? '-').')',
                'ip_address' => request()?->ip(),
            ]);
        });

        static::deleted(function (User $user): void {
            if (! auth()->check()) {
                return;
            }

            UserLog::create([
                'user_id' => auth()->id(),
                'action' => 'Delete',
                'module' => 'User',
                'description' => 'Menghapus user '.(string) ($user->id_custom ?: ('#'.$user->id)).' ('.(string) ($user->name ?? '-').')',
                'ip_address' => request()?->ip(),
            ]);
        });
    }
}
