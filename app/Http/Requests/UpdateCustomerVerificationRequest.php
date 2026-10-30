<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerVerificationRequest extends FormRequest
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
            'status' => ['required', Rule::in(['flagged', 'verified', 'approved', 'rejected'])],
            'failed_step' => ['nullable', 'required_if:status,flagged', Rule::in(['contact', 'address'])],
            'matches' => ['present', 'array'],
            'matches.*' => ['string', 'max:500'],
            'process_instance_id' => ['required', 'string', 'max:64'],
            'run_id' => ['nullable', 'integer'],
        ];
    }
}
