<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use App\Models\PurchaseRequest;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('project_display')
                    ->label('Kode Proyek')
                    ->content(fn (?PurchaseRequest $record): HtmlString => self::asBadge($record?->project?->kode_proyek)),
                Placeholder::make('material_display')
                    ->label('Material')
                    ->content(fn (?PurchaseRequest $record): HtmlString => self::asBadge($record?->material?->nama_material)),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->disabled(),
                TextInput::make('priority')
                    ->label('Prioritas')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'urgent' => 'CRITICAL',
                        'high' => 'HIGH',
                        'medium' => 'MEDIUM',
                        'low' => 'LOW',
                        default => strtoupper($state),
                    })
                    ->disabled(),
                DatePicker::make('needed_date')
                    ->label('Tanggal Dibutuhkan')
                    ->disabled(),
                Textarea::make('reason')
                    ->label('Reason')
                    ->rows(3)
                    ->disabled(),
                TextInput::make('status')
                    ->label('Status')
                    ->disabled(),
                Placeholder::make('status_preview')
                    ->label('Preview Status')
                    ->content(fn (?PurchaseRequest $record): HtmlString => self::statusBadge($record?->status)),
                TextInput::make('created_at')
                    ->label('Draft Dibuat')
                    ->disabled(),
                TextInput::make('submitted_at')
                    ->label('Draft Terkirim')
                    ->disabled(),
                Textarea::make('rejection_reason')
                    ->label('Alasan Reject')
                    ->rows(3)
                    ->disabled(),
            ]);
    }

    private static function asBadge(?string $value): HtmlString
    {
        $text = e($value ?: '-');

        return new HtmlString("<span class=\"inline-flex items-center rounded-full border border-gray-300 bg-gray-50 px-3 py-1 text-sm font-medium text-gray-700\">{$text}</span>");
    }

    private static function statusBadge(?string $status): HtmlString
    {
        $text = match ($status) {
            'rejected' => 'Rejected',
            'draft' => 'Draft',
            default => '-',
        };

        $classes = match ($status) {
            'rejected' => 'border-red-300 bg-red-50 text-red-700',
            'draft' => 'border-gray-300 bg-gray-50 text-gray-700',
            default => 'border-slate-300 bg-slate-50 text-slate-600',
        };

        return new HtmlString("<span class=\"inline-flex items-center rounded-full border px-3 py-1 text-sm font-medium {$classes}\">" . e($text) . '</span>');
    }
}
