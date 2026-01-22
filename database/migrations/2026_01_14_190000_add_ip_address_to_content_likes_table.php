<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adiciona suporte para likes de usuários não logados (por IP)
     */
    public function up(): void
    {
        Schema::table('content_likes', function (Blueprint $table) {
            // Torna user_id opcional (pode ser null para usuários não logados)
            $table->unsignedBigInteger('user_id')->nullable()->change();
            
            // Adiciona coluna de IP para identificar likes de usuários não logados
            $table->string('ip_address', 45)->nullable()->after('user_id');
            
            // Adiciona índice para busca por IP
            $table->index('ip_address');
        });
        
        // Atualiza o constraint único para incluir IP
        Schema::table('content_likes', function (Blueprint $table) {
            // Remove o índice único antigo
            $table->dropUnique('unique_user_like');
            
            // Cria novo índice único que considera IP também
            $table->unique(['user_id', 'ip_address', 'likeable_id', 'likeable_type'], 'unique_like_identifier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('content_likes', function (Blueprint $table) {
            $table->dropUnique('unique_like_identifier');
            $table->dropColumn('ip_address');
            $table->unique(['user_id', 'likeable_id', 'likeable_type'], 'unique_user_like');
        });
    }
};
