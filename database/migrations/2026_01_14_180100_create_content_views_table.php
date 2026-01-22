<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabela para armazenar views de conteúdo (álbuns, vídeos individuais)
     */
    public function up(): void
    {
        Schema::create('content_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            
            // Polymorphic relation - pode ser album, media_item, etc
            $table->morphs('viewable'); // cria viewable_id e viewable_type
            
            // Dados da visualização
            $table->string('ip_address', 45)->nullable(); // IPv6 support
            $table->string('user_agent', 500)->nullable();
            $table->string('referer', 500)->nullable();
            $table->string('country', 2)->nullable(); // Código ISO do país
            $table->string('device_type', 20)->nullable(); // desktop, mobile, tablet
            
            $table->timestamp('viewed_at')->useCurrent();
            
            // Índices para performance e analytics
            $table->index(['viewable_id', 'viewable_type']);
            $table->index('user_id');
            $table->index('ip_address');
            $table->index('viewed_at');
            $table->index('country');
            
            // Prevenir contagem duplicada em curto período (mesmo IP/user para mesmo conteúdo)
            // Não usamos unique aqui porque queremos permitir múltiplas views ao longo do tempo
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_views');
    }
};
