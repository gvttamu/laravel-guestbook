<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * Return a single customer as a bare object rather than under a "data" key,
     * which is simpler to map in workflow engine connectors. Paginated lists
     * keep the "data" key alongside their "links" and "meta".
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],
            'financials' => [
                'monthly_income' => $this->monthly_income,
                'other_income' => $this->other_income,
                'rent_or_mortgage' => $this->rent_or_mortgage,
                'car_payments' => $this->car_payments,
                'other_debt_payments' => $this->other_debt_payments,
                'living_expenses' => $this->living_expenses,
            ],
            'loan' => [
                'amount' => $this->loan_amount,
                'term_months' => $this->loan_term_months,
                'purpose' => $this->loan_purpose,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
