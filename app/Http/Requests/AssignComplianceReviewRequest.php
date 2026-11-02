<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Workflows\CustomerVerification\CustomerVerification;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignComplianceReviewRequest extends FormRequest
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
            // The step the officer saw, so the assignment can't land on a review that has since been decided.
            'step' => ['required', Rule::in(CustomerVerification::REVIEW_PLACES)],
            // Empty leaves the review unassigned, for any officer to pick up.
            // Reassigning hands the review to someone else, so officers can't assign it to themselves.
            'assignee_id' => ['nullable', 'integer', Rule::notIn([$this->user()->id]), Rule::exists('users', 'id')->where('role', User::ROLE_COMPLIANCE)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.not_in' => 'Choose another compliance officer.',
            'assignee_id.exists' => 'Choose a compliance officer.',
        ];
    }

    /**
     * The officer chosen, or null to leave the review unassigned.
     */
    public function assignee(): ?User
    {
        return User::find($this->validated('assignee_id'));
    }
}
