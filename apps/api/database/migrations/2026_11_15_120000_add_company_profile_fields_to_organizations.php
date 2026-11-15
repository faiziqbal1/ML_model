<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('logo_url')->nullable();
            $table->text('description')->nullable();
            $table->string('industry')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('timezone', 64)->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'logo_url',
                'description',
                'industry',
                'contact_email',
                'phone',
                'address',
                'timezone',
            ]);
        });
    }
};
