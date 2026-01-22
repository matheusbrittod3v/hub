<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address')->unique();
            $table->timestamp('blocked_until');
            $table->string('reason')->nullable();
            $table->integer('reports_accumulator')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_blocks');
    }
};
