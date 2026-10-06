<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "only_approved" (boolean) became "approval_filter". The new default is to
     * analyse comments that are not approved yet, so the old value is dropped.
     */
    public function up(): void
    {
        DB::table('settings')->where('key', 'only_approved')->delete();
    }

    public function down(): void
    {
        //
    }
};
