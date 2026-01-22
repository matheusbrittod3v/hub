<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Corrige a tabela content_likes para permitir user_id nulo
     */
    public function up(): void
    {
        // Verificar se a tabela existe
        if (!Schema::hasTable('content_likes')) {
            return;
        }
        
        // Dropar foreign key se existir
        Schema::table('content_likes', function (Blueprint $table) {
            // Tentar dropar a foreign key (pode ter nomes diferentes dependendo do banco)
            try {
                $table->dropForeign(['user_id']);
            } catch (\Exception $e) {
                // Ignora se não existir
            }
        });
        
        // Modificar a coluna user_id para permitir null usando raw SQL
        DB::statement('ALTER TABLE content_likes MODIFY user_id BIGINT UNSIGNED NULL');
        
        // Adicionar ip_address se não existir
        if (!Schema::hasColumn('content_likes', 'ip_address')) {
            Schema::table('content_likes', function (Blueprint $table) {
                $table->string('ip_address', 45)->nullable()->after('user_id');
                $table->index('ip_address');
            });
        }
        
        // Remover índice único antigo se existir
        try {
            Schema::table('content_likes', function (Blueprint $table) {
                $table->dropUnique('unique_user_like');
            });
        } catch (\Exception $e) {
            // Ignora se não existir
        }
        
        // Verificar se o novo índice já existe
        $indexExists = collect(DB::select("SHOW INDEX FROM content_likes WHERE Key_name = 'unique_like_identifier'"))->isNotEmpty();
        
        if (!$indexExists) {
            // Criar novo índice único
            DB::statement('CREATE UNIQUE INDEX unique_like_identifier ON content_likes (user_id, ip_address, likeable_id, likeable_type)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Não é necessário reverter - a tabela original foi modificada
    }
};
