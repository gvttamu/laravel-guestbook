<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Http\Controllers\CustomerDraftController;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Users with this role review customers flagged by the verification workflow.
     * The role is not mass assignable; set it with `php artisan user:create --compliance`.
     */
    public const ROLE_COMPLIANCE = 'compliance';

    public function isComplianceOfficer(): bool
    {
        return $this->role === self::ROLE_COMPLIANCE;
    }

    /**
     * @return HasMany<FormDraft, $this>
     */
    public function formDrafts(): HasMany
    {
        return $this->hasMany(FormDraft::class);
    }

    /**
     * How many items the inbox holds: customers flagged for review for compliance officers,
     * and their own customer drafts for users who capture customers. Counted from the
     * database so every page can show it without asking the workflow engine; a flagged
     * verification run is one with an open review task, since a decision moves it back to "running".
     */
    public function inboxCount(): int
    {
        $reviews = $this->can('review-compliance')
            ? WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)->where('status', 'flagged')->count()
            : 0;

        $drafts = $this->can('capture-customers')
            ? $this->formDrafts()->where('form_type', CustomerDraftController::FORM_TYPE)->count()
            : 0;

        return $reviews + $drafts;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
