<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('tasks')
            ->where('type', 'pr_finance_approved_followup')
            ->delete();
    }

    public function down(): void
    {
        // no-op: deleted operational tasks cannot be restored safely
    }
};
