<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number')->nullable();
        });
    }
};
