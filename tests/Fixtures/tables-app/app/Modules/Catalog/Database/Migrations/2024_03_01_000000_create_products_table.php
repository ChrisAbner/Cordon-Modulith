<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
        });
    }
};
