<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\Material;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_gudang_staff_can_view_create_and_edit_own_draft(): void
    {
        $user = User::factory()->create(['role' => 'Staff Administrasi Gudang']);
        $material = $this->createMaterial();

        $draft = PurchaseRequest::create([
            'material_id' => $material->id,
            'requested_by' => $user->id,
            'quantity' => 10,
            'status' => 'draft',
            'priority' => 'medium',
        ]);

        $this->actingAs($user);

        $this->assertTrue(PurchaseRequestResource::canViewAny());
        $this->assertTrue(PurchaseRequestResource::canCreate());
        $this->assertTrue(PurchaseRequestResource::canEdit($draft));
    }

    public function test_manager_can_view_and_only_see_pending_manager_records(): void
    {
        $manager = User::factory()->create(['role' => 'Manager Operasional']);
        $requester = User::factory()->create(['role' => 'Staff Administrasi Gudang']);
        $material = $this->createMaterial();

        $pendingManager = PurchaseRequest::create([
            'material_id' => $material->id,
            'requested_by' => $requester->id,
            'quantity' => 5,
            'status' => 'pending_manager',
            'priority' => 'high',
        ]);

        $pendingFinance = PurchaseRequest::create([
            'material_id' => $material->id,
            'requested_by' => $requester->id,
            'quantity' => 7,
            'status' => 'pending_finance',
            'priority' => 'high',
        ]);

        $this->actingAs($manager);

    $this->assertTrue(PurchaseRequestResource::canViewAny());
    $this->assertFalse(PurchaseRequestResource::canCreate());
    $this->assertFalse(PurchaseRequestResource::canEdit($pendingManager));

        $visibleIds = PurchaseRequestResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($pendingManager->id, $visibleIds);
        $this->assertNotContains($pendingFinance->id, $visibleIds);
    }

    public function test_finance_can_view_and_only_see_pending_finance_records(): void
    {
        $finance = User::factory()->create(['role' => 'Finance']);
        $requester = User::factory()->create(['role' => 'Staff Administrasi Gudang']);
        $material = $this->createMaterial();

        $pendingManager = PurchaseRequest::create([
            'material_id' => $material->id,
            'requested_by' => $requester->id,
            'quantity' => 8,
            'status' => 'pending_manager',
            'priority' => 'medium',
        ]);

        $pendingFinance = PurchaseRequest::create([
            'material_id' => $material->id,
            'requested_by' => $requester->id,
            'quantity' => 9,
            'status' => 'pending_finance',
            'priority' => 'urgent',
        ]);

        $this->actingAs($finance);

    $this->assertTrue(PurchaseRequestResource::canViewAny());
    $this->assertFalse(PurchaseRequestResource::canCreate());
    $this->assertFalse(PurchaseRequestResource::canEdit($pendingFinance));

        $visibleIds = PurchaseRequestResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($pendingFinance->id, $visibleIds);
        $this->assertNotContains($pendingManager->id, $visibleIds);
    }

    public function test_other_roles_cannot_access_purchase_request_module(): void
    {
        $user = User::factory()->create(['role' => 'Administrator IT']);
        $material = $this->createMaterial();

        $record = PurchaseRequest::create([
            'material_id' => $material->id,
            'requested_by' => $user->id,
            'quantity' => 3,
            'status' => 'draft',
            'priority' => 'low',
        ]);

        $this->actingAs($user);

        $this->assertFalse(PurchaseRequestResource::canViewAny());
        $this->assertFalse(PurchaseRequestResource::canCreate());
        $this->assertFalse(PurchaseRequestResource::canEdit($record));
        $this->assertCount(0, PurchaseRequestResource::getEloquentQuery()->get());
    }

    private function createMaterial(): Material
    {
        return Material::create([
            'kode_material' => 'MAT-' . uniqid(),
            'nama_material' => 'Material Test',
            'satuan' => 'pcs',
            'stok' => 0,
            'min_stok' => 0,
        ]);
    }

}
