<?php

namespace App\Models;

use Database\Factories\FormDraftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Work-in-progress form data, autosaved separately from the real entities so
 * incomplete input never triggers validation, audit records, or workflows.
 *
 * A draft with a customer holds changes to that customer. It remembers the
 * customer's version and details when editing began, so saving can detect
 * changes someone else made in the meantime.
 */
class FormDraft extends Model
{
    /** @use HasFactory<FormDraftFactory> */
    use HasFactory, HasUuids, Prunable;

    /**
     * Drafts untouched for this many days are deleted by `model:prune`.
     */
    public const RETENTION_DAYS = 30;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'form_type',
        'user_id',
        'customer_id',
        'customer_version',
        'schema_version',
        'payload',
        'original_payload',
        'current_step',
        'version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'original_payload' => 'array',
            'customer_version' => 'integer',
            'schema_version' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * The user who started the draft. Only they can open, save, submit, or discard it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The customer this draft edits, or null for a draft of a new customer.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the prunable model query.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('updated_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
