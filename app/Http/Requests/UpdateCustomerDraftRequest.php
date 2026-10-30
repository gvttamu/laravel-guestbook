<?php

namespace App\Http\Requests;

use App\Http\Controllers\CustomerDraftController;
use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autosave validation only checks that the data is safe to store. Required fields,
 * email format, and uniqueness are checked when the draft is submitted.
 */
class UpdateCustomerDraftRequest extends FormRequest
{
    /**
     * Ownership is enforced by the customerDraft route binding.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'monthly_income' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'other_income' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'rent_or_mortgage' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'car_payments' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'other_debt_payments' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'living_expenses' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'loan_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'loan_term_months' => ['nullable', 'integer', 'min:0', 'max:'.Customer::MAX_LOAN_TERM_MONTHS],
            'loan_purpose' => ['nullable', Rule::in(Customer::LOAN_PURPOSES)],
            'loan_purpose_details' => ['nullable', 'string', 'max:500'],
            'current_step' => ['required', Rule::in(CustomerDraftController::STEPS)],
            'version' => ['required', 'integer'],
        ];
    }
}
