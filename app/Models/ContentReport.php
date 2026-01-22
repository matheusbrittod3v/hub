<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class ContentReport extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'content_reports';

    /**
     * The database connection (banco padrão).
     */
    protected $connection = 'mysql';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'reporter_id',
        'reportable_id',
        'reportable_type',
        'reason',
        'description',
        'ip_address',
        'user_agent',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
        'action_taken',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Valid report reasons.
     */
    public const REASONS = [
        'spam' => 'Spam ou propaganda',
        'illegal' => 'Conteúdo ilegal',
        'underage' => 'Possível menor de idade',
        'violence' => 'Violência extrema',
        'non_consensual' => 'Conteúdo não consensual',
        'copyright' => 'Violação de direitos autorais',
        'harassment' => 'Assédio ou bullying',
        'misinformation' => 'Informação falsa',
        'other' => 'Outros',
    ];

    /**
     * Valid report statuses.
     */
    public const STATUSES = [
        'pending' => 'Aguardando análise',
        'reviewing' => 'Em análise',
        'resolved' => 'Resolvido',
        'dismissed' => 'Descartado',
        'escalated' => 'Escalado',
    ];

    /**
     * Valid actions that can be taken.
     */
    public const ACTIONS = [
        'none' => 'Nenhuma ação',
        'warning' => 'Aviso ao usuário',
        'content_removed' => 'Conteúdo removido',
        'user_suspended' => 'Usuário suspenso',
        'user_banned' => 'Usuário banido',
        'reported_authorities' => 'Reportado às autoridades',
    ];

    /**
     * Get the user who reported the content.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * Get the user who reviewed the report.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the reportable model (Album, MediaItem, User, etc).
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create a new report.
     */
    public static function createReport(
        string $reportableType,
        int $reportableId,
        string $reason,
        ?string $description = null,
        ?int $reporterId = null,
        ?Request $request = null
    ): self {
        return self::create([
            'reporter_id' => $reporterId,
            'reportable_type' => $reportableType,
            'reportable_id' => $reportableId,
            'reason' => $reason,
            'description' => $description,
            'ip_address' => $request?->ip(),
            'user_agent' => substr($request?->userAgent() ?? '', 0, 500),
            'status' => 'pending',
        ]);
    }

    /**
     * Mark report as being reviewed.
     */
    public function startReview(int $reviewerId): self
    {
        $this->update([
            'status' => 'reviewing',
            'reviewed_by' => $reviewerId,
        ]);
        
        return $this;
    }

    /**
     * Resolve the report.
     */
    public function resolve(
        int $reviewerId,
        string $action,
        ?string $notes = null
    ): self {
        $this->update([
            'status' => 'resolved',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'action_taken' => $action,
            'resolution_notes' => $notes,
        ]);
        
        return $this;
    }

    /**
     * Dismiss the report.
     */
    public function dismiss(int $reviewerId, ?string $notes = null): self
    {
        $this->update([
            'status' => 'dismissed',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'action_taken' => 'none',
            'resolution_notes' => $notes,
        ]);
        
        return $this;
    }

    /**
     * Escalate the report.
     */
    public function escalate(int $reviewerId, ?string $notes = null): self
    {
        $this->update([
            'status' => 'escalated',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'resolution_notes' => $notes,
        ]);
        
        return $this;
    }

    /**
     * Check if content has pending reports.
     */
    public static function hasPendingReports(string $reportableType, int $reportableId): bool
    {
        return self::where('reportable_type', $reportableType)
            ->where('reportable_id', $reportableId)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Count reports for content.
     */
    public static function countReports(string $reportableType, int $reportableId): int
    {
        return self::where('reportable_type', $reportableType)
            ->where('reportable_id', $reportableId)
            ->count();
    }

    /**
     * Scope for pending reports.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for high priority reports (illegal, underage, non_consensual).
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('reason', ['illegal', 'underage', 'non_consensual']);
    }

    /**
     * Get reason label.
     */
    public function getReasonLabelAttribute(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
