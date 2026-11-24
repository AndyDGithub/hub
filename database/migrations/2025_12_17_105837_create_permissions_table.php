<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'description')) {
                $table->string('description')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasColumn('permissions', 'description')) {
            return;
        }

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
