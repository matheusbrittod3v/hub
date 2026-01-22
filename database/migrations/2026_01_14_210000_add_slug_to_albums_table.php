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
        // Usando conexão mysql_content onde está a tabela albums
        Schema::connection('mysql_content')->table('albums', function (Blueprint $table) {
            // Adicionar coluna slug otimizada para SEO
            $table->string('slug', 255)->nullable()->after('title');
            
            // Índice para busca rápida por slug
            $table->index('slug');
            
            // Índice composto para busca por album_id + slug (validação de URL)
            $table->index(['album_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mysql_content')->table('albums', function (Blueprint $table) {
            $table->dropIndex(['slug']);
            $table->dropIndex(['album_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};
