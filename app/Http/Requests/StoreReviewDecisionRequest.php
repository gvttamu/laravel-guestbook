<?php

namespace App\Http\Requests;

use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReviewDecisionRequest extends FormRequest
{
    /**
     * Access is limited to compliance officers by the route middleware.
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
            // The step the officer reviewed, so a decision can't land on a different step the run has since moved to.
            'step' => ['required', Rule::in(CustomerVerification::REVIEW_PLACES)],
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Choose whether to approve or reject this customer.',
            'notes.required_if' => 'Explain why you are rejecting this customer.',
        ];
    }
}
