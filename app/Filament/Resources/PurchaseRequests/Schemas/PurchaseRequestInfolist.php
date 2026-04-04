<?php

namespace App\Filament\Resources\PurchaseRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PurchaseRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('project.kode_proyek')
                    ->label('Kode Proyek')
                    ->placeholder('-'),
                TextEntry::make('material.nama_material')
                    ->label('Material')
                    ->placeholder('-'),
                TextEntry::make('quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 2),
                TextEntry::make('priority')
                    ->label('Prioritas')
                    ->badge(),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('requester.name')
                    ->label('Requester')
                    ->placeholder('-'),
                TextEntry::make('draftApprover.name')
                    ->label('Draft Approved By')
                    ->placeholder('-'),
                TextEntry::make('draft_approved_at')
                    ->label('Draft Approved At')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('submitted_at')
                    ->label('Submitted At')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('reason')
                    ->label('Reason')
                    ->columnSpanFull()
                    ->placeholder('-'),
                TextEntry::make('rejection_reason')
                    ->label('Alasan Reject')
                    ->columnSpanFull()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->label('Created At')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
