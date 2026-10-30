<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerScoringRequest extends FormRequest
{
    /**
     * Access is limited to tokens with the verify ability by the route middleware.
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
            'score' => ['required', 'integer', 'between:0,100'],
            'risk_band' => ['required', Rule::in(['low', 'medium', 'high'])],
            'debt_to_income' => ['nullable', 'numeric', 'min:0'],
            'disposable_income' => ['required', 'numeric'],
            'loan_repayment' => ['nullable', 'numeric', 'min:0'],
            'reasons' => ['present', 'array'],
            'reasons.*' => ['string', 'max:500'],
            'process_instance_id' => ['required', 'string', 'max:64'],
            'run_id' => ['nullable', 'integer'],
        ];
    }
}
