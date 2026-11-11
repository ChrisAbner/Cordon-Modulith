<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('user_id')->constrained();
            $table->foreignIdFor(\App\Models\Order::class)->constrained();
            $table->foreignId('owner_ref')->constrained($this->target);
        });
    }
};
