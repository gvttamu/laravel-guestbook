<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    /**
     * The token ability required to read customers.
     */
    public const READ_ABILITY = 'customers:read';

    /**
     * List customers, 25 per page. Filter with `?email=` to look one up by email address.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->when($request->filled('email'), fn (Builder $query) => $query->where('email', $request->string('email')->toString()))
            ->orderBy('id')
            ->paginate(25);

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }
}
