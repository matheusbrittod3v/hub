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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('room')->default('geral');
            $table->string('nickname')->nullable();
            $table->text('message')->nullable();
            $table->enum('type', ['text', 'image', 'video', 'bbcode'])->default('text');
            $table->json('meta')->nullable(); // Store extra info like image path, video path, or emoji data
            $table->string('ip_address')->nullable();
            $table->integer('reports_count')->default(0);
            $table->timestamps();

            $table->index(['room', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
