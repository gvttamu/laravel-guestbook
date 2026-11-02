<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Http\Controllers\CustomerDraftController;
use App\Workflows\FinancialScoring\CreditRiskTier;
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

    /**
     * Users with this role decide loan applications the decision engine refers in their credit
     * risk tier (`credit_risk_tier`), and can reconsider any decided application. Set both with
     * `php artisan user:create --credit=<tier>`.
     */
    public const ROLE_CREDIT = 'credit';

    public function isComplianceOfficer(): bool
    {
        return $this->role === self::ROLE_COMPLIANCE;
    }

    public function isCreditOfficer(): bool
    {
        return $this->role === self::ROLE_CREDIT;
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
     * Credit officers who may decide an application of the given credit risk tier: those of that tier or a riskier one.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function creditOfficersFor(Builder $query, string $tier): void
    {
        $query->where('role', self::ROLE_CREDIT)->whereIn('credit_risk_tier', CreditRiskTier::atOrAbove($tier));
    }

    /**
     * @return HasMany<FormDraft, $this>
     */
    public function formDrafts(): HasMany
    {
        return $this->hasMany(FormDraft::class);
    }

    /**
     * How many items the inbox holds: customers flagged for review that are unassigned or assigned
     * to them for compliance officers, referred loan applications likewise for credit officers, and their
     * own customer drafts for users who capture customers.
     */
    public function inboxCount(): int
    {
        $reviews = $this->can('review-compliance')
            ? WorkflowInstance::awaitingReviewBy($this)->count()
            : 0;

        $creditReviews = $this->can('review-credit')
            ? WorkflowInstance::awaitingCreditReviewBy($this)->count()
            : 0;

        $drafts = $this->can('capture-customers')
            ? $this->formDrafts()->where('form_type', CustomerDraftController::FORM_TYPE)->count()
            : 0;

        return $reviews + $creditReviews + $drafts;
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
