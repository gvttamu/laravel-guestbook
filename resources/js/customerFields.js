// Customer form fields, grouped into the steps of the new-customer form.
// The edit page shows the same groups as tabs.

// Attributes for a money input: whole cents, never negative, with a numeric keypad on phones.
export const moneyInputAttributes = { min: 0, step: '0.01', inputmode: 'decimal' };

// Keys match Customer::LOAN_PURPOSES.
const loanPurposes = [
    { value: 'home_improvement', label: 'Home improvement' },
    { value: 'car', label: 'Car' },
    { value: 'debt_consolidation', label: 'Debt consolidation' },
    { value: 'education', label: 'Education' },
    { value: 'business', label: 'Business' },
    { value: 'other', label: 'Other' },
];

export const customerSections = [
    {
        key: 'basic',
        title: 'Basic details',
        fields: [
            { name: 'first_name', label: 'First name', required: true, autocomplete: 'given-name' },
            { name: 'last_name', label: 'Last name', required: true, autocomplete: 'family-name' },
            { name: 'email', label: 'Email', type: 'email', required: true, autocomplete: 'email' },
            { name: 'phone', label: 'Phone', type: 'tel', required: true, autocomplete: 'tel' },
        ],
    },
    {
        key: 'details',
        title: 'More details',
        fields: [
            { name: 'date_of_birth', label: 'Date of birth', type: 'date', pastOnly: true },
            { name: 'address_line1', label: 'Address', required: true, autocomplete: 'address-line1' },
            { name: 'address_line2', label: 'Address line 2', autocomplete: 'address-line2' },
            { name: 'city', label: 'City', required: true, autocomplete: 'address-level2' },
            { name: 'postal_code', label: 'Postal code', required: true, autocomplete: 'postal-code' },
            { name: 'country', label: 'Country', required: true, autocomplete: 'country-name' },
        ],
    },
    {
        key: 'financials',
        title: 'Financials',
        description: 'Monthly amounts. Leave an outgoing blank if the customer doesn’t have it.',
        fields: [
            { name: 'monthly_income', label: 'Monthly income after tax', type: 'number', attributes: moneyInputAttributes, required: true },
            { name: 'other_income', label: 'Other monthly income', type: 'number', attributes: moneyInputAttributes },
            { name: 'rent_or_mortgage', label: 'Rent or mortgage', type: 'number', attributes: moneyInputAttributes },
            { name: 'car_payments', label: 'Car payments', type: 'number', attributes: moneyInputAttributes },
            { name: 'other_debt_payments', label: 'Loan and credit card payments', type: 'number', attributes: moneyInputAttributes },
            { name: 'living_expenses', label: 'Living expenses (food, utilities, transport)', type: 'number', attributes: moneyInputAttributes },
        ],
    },
    {
        key: 'loan',
        title: 'Loan',
        description: 'The loan the customer is applying for.',
        fields: [
            { name: 'loan_amount', label: 'Loan amount', type: 'number', attributes: { ...moneyInputAttributes, min: 1 }, required: true },
            { name: 'loan_term_months', label: 'Repayment term (months)', type: 'number', attributes: { min: 1, max: 360, step: 1, inputmode: 'numeric' }, required: true },
            { name: 'loan_purpose', label: 'Purpose', type: 'select', options: loanPurposes, required: true },
            { name: 'loan_purpose_details', label: 'About the purpose', type: 'textarea', hint: 'Required when the purpose is Other.' },
        ],
    },
];

export const customerFieldLabels = Object.fromEntries(customerSections.flatMap((section) => section.fields.map((field) => [field.name, field.label])));

// The label to show for a stored value, for fields with a fixed list of options.
export function customerFieldValueLabel(name, value) {
    const field = customerSections.flatMap((section) => section.fields).find((candidate) => candidate.name === name);

    return field?.options?.find((option) => option.value === value)?.label ?? value;
}
