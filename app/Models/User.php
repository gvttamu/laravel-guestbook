<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Http\Controllers\CustomerDraftController;
use App\Workflows\CustomerVerification\CustomerVerification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function complianceOfficers(Builder $query): void
    {
        $query->where('role', self::ROLE_COMPLIANCE);
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
     * and their own customer drafts for users who capture customers.
     */
    public function inboxCount(): int
    {
        $reviews = $this->can('review-compliance')
            ? WorkflowInstance::where('workflow', WorkflowInstance::CUSTOMER_VERIFICATION)->whereIn('status', array_keys(CustomerVerification::REVIEW_PLACES))->count()
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
