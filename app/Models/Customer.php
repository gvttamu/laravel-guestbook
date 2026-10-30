<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Validation\Rule;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'country',
        'monthly_income',
        'other_income',
        'rent_or_mortgage',
        'car_payments',
        'other_debt_payments',
        'living_expenses',
        'loan_amount',
        'loan_term_months',
        'loan_purpose',
        'loan_purpose_details',
    ];

    /**
     * The fields the verification engine checks against the sanctions list.
     * Changing any of them means the customer has to be checked again.
     *
     * @var list<string>
     */
    public const SCREENED_FIELDS = ['phone', 'email', 'address_line1', 'postal_code', 'country'];

    /**
     * The customer's monthly income and outgoings.
     *
     * @var list<string>
     */
    public const FINANCIAL_FIELDS = ['monthly_income', 'other_income', 'rent_or_mortgage', 'car_payments', 'other_debt_payments', 'living_expenses'];

    /**
     * The fields the financial scoring process scores: the financials, and the loan's
     * amount and term, which set its estimated repayment. Changing any of them means the
     * customer has to be scored again.
     *
     * @var list<string>
     */
    public const SCORED_FIELDS = [...self::FINANCIAL_FIELDS, 'loan_amount', 'loan_term_months'];

    /**
     * The loan the customer is applying for.
     *
     * @var list<string>
     */
    public const LOAN_FIELDS = ['loan_amount', 'loan_term_months', 'loan_purpose', 'loan_purpose_details'];

    /**
     * What a loan can be for. The form's labels for these are in resources/js/customerFields.js.
     *
     * @var list<string>
     */
    public const LOAN_PURPOSES = ['home_improvement', 'car', 'debt_consolidation', 'education', 'business', 'other'];

    /**
     * The longest repayment term offered, in months.
     */
    public const MAX_LOAN_TERM_MONTHS = 360;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'version' => 'integer',
            'monthly_income' => 'decimal:2',
            'other_income' => 'decimal:2',
            'rent_or_mortgage' => 'decimal:2',
            'car_payments' => 'decimal:2',
            'other_debt_payments' => 'decimal:2',
            'living_expenses' => 'decimal:2',
            'loan_amount' => 'decimal:2',
            'loan_term_months' => 'integer',
        ];
    }

    /**
     * The full validation rules for a customer, used when a draft is submitted
     * and when an existing customer is edited.
     *
     * @return array<string, list<mixed>>
     */
    public static function validationRules(?self $ignore = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('customers')->ignore($ignore)],
            'phone' => ['required', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'max:100'],
            'monthly_income' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'other_income' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'rent_or_mortgage' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'car_payments' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'other_debt_payments' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'living_expenses' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'loan_amount' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:9999999999.99'],
            'loan_term_months' => ['required', 'integer', 'between:1,'.self::MAX_LOAN_TERM_MONTHS],
            'loan_purpose' => ['required', Rule::in(self::LOAN_PURPOSES)],
            'loan_purpose_details' => ['nullable', 'required_if:loan_purpose,other', 'string', 'max:500'],
        ];
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * Every workflow run for this customer, including superseded ones.
     *
     * @return MorphMany<WorkflowInstance, $this>
     */
    public function workflows(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject');
    }

    /**
     * The customer's most recent verification run.
     *
     * @return MorphOne<WorkflowInstance, $this>
     */
    public function workflow(): MorphOne
    {
        return $this->latestRunOf(WorkflowInstance::CUSTOMER_VERIFICATION);
    }

    /**
     * The customer's most recent financial scoring run.
     *
     * @return MorphOne<WorkflowInstance, $this>
     */
    public function scoring(): MorphOne
    {
        return $this->latestRunOf(WorkflowInstance::FINANCIAL_SCORING);
    }

    /**
     * Whether the customer has entered their income. Customers added before financials
     * were captured haven't, and can't be scored until they do.
     */
    public function hasFinancials(): bool
    {
        return $this->monthly_income !== null;
    }

    /**
     * Find and lock the run of the given workflow that an engine report is about. The
     * engine can report before the start job has stored the process id, so a report is
     * also matched by the run id the process was started with.
     */
    public function lockRunForReport(string $workflow, string $processInstanceId, ?int $runId): ?WorkflowInstance
    {
        $runs = $this->workflows()->where('workflow', $workflow)->lockForUpdate();

        return (clone $runs)->where('external_id', $processInstanceId)->first()
            ?? ($runId === null ? null : (clone $runs)->whereKey($runId)->whereNull('external_id')->first());
    }

    /**
     * @return MorphOne<WorkflowInstance, $this>
     */
    private function latestRunOf(string $workflow): MorphOne
    {
        return $this->morphOne(WorkflowInstance::class, 'subject')
            ->ofMany(['id' => 'max'], fn (Builder $query) => $query->where('workflow', $workflow));
    }
}
