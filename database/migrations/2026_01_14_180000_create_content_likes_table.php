<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabela para armazenar likes de conteúdo (álbuns, vídeos individuais, fotos)
     */
    public function up(): void
    {
        Schema::create('content_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Polymorphic relation - pode ser album, media_item, etc
            $table->morphs('likeable'); // cria likeable_id e likeable_type
            
            $table->timestamp('created_at')->useCurrent();
            
            // Índice único para evitar likes duplicados
            $table->unique(['user_id', 'likeable_id', 'likeable_type'], 'unique_user_like');
            
            // Índices para performance
            $table->index(['likeable_id', 'likeable_type']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_likes');
    }
};
