<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Tabela para armazenar denúncias de conteúdo
     */
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            
            // Quem denunciou
            $table->foreignId('reporter_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Polymorphic relation - pode ser album, media_item, user, comment, etc
            $table->morphs('reportable'); // cria reportable_id e reportable_type
            
            // Tipo/motivo da denúncia
            $table->enum('reason', [
                'spam',           // Spam ou propaganda
                'illegal',        // Conteúdo ilegal
                'underage',       // Possível menor de idade
                'violence',       // Violência extrema
                'non_consensual', // Conteúdo não consensual
                'copyright',      // Violação de direitos autorais
                'harassment',     // Assédio ou bullying
                'misinformation', // Informação falsa
                'other'           // Outros
            ])->default('other');
            
            // Detalhes adicionais
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            
            // Status da denúncia
            $table->enum('status', [
                'pending',    // Aguardando análise
                'reviewing',  // Em análise
                'resolved',   // Resolvido
                'dismissed',  // Descartado/inválido
                'escalated'   // Escalado para autoridades
            ])->default('pending');
            
            // Quem analisou e ação tomada
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->enum('action_taken', [
                'none',           // Nenhuma ação
                'warning',        // Aviso ao usuário
                'content_removed',// Conteúdo removido
                'user_suspended', // Usuário suspenso
                'user_banned',    // Usuário banido
                'reported_authorities' // Reportado às autoridades
            ])->nullable();
            
            $table->timestamps();
            
            // Índices
            $table->index(['reportable_id', 'reportable_type']);
            $table->index('reporter_id');
            $table->index('status');
            $table->index('reason');
            $table->index('created_at');
            $table->index(['status', 'created_at']); // Para listar pendentes por ordem
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_reports');
    }
};
