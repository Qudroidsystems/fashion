<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared validation for creating/updating a customer (web + API). */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->isMethod('post') ? 'Create customer' : 'Update customer');
    }

    public function rules(): array
    {
        $tenantId = CurrentTenant::id();

        $rules = [
            'title'        => 'nullable|string|max:20',
            'first_name'   => 'required|string|max:80',
            'last_name'    => 'nullable|string|max:80',
            'gender'       => ['nullable', Rule::in(['male', 'female', 'other'])],
            'phone'        => 'nullable|string|max:30|required_without:email',
            'alt_phone'    => 'nullable|string|max:30',
            'whatsapp'     => 'nullable|string|max:30',
            'email'        => 'nullable|email|max:255|required_without:phone',
            'birthday'     => 'nullable|date|before:today',
            'address'      => 'nullable|string|max:255',
            'city'         => 'nullable|string|max:80',
            'state'        => 'nullable|string|max:80',
            'occupation'   => 'nullable|string|max:100',
            'source'       => ['nullable', Rule::in(array_keys(Customer::SOURCES))],
            'is_vip'       => 'nullable|boolean',
            'branch_id'    => ['nullable', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'assigned_to'  => ['nullable', Rule::exists('tenant_memberships', 'user_id')->where('tenant_id', $tenantId)],
            'tags'         => 'nullable|array|max:15',
            'tags.*'       => 'string|max:40',
            'preferences'  => 'nullable|array',
            'preferences.*'=> 'nullable|string|max:255',
            'consents'     => 'nullable|array',
            'consents.*'   => 'array',
            'consents.*.*' => 'boolean',
            'confirm_duplicate' => 'nullable|boolean',
        ];

        // PATCH (API) = partial update: only validate what was sent.
        if ($this->isMethod('patch')) {
            $rules['first_name'] = 'sometimes|required|string|max:80';
            $rules['phone'] = 'nullable|string|max:30';
            $rules['email'] = 'nullable|email|max:255';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'phone.required_without' => 'Enter a phone number or an e-mail address.',
            'email.required_without' => 'Enter a phone number or an e-mail address.',
        ];
    }

    /** Only the customer table's own fields. */
    public function customerData(): array
    {
        $data = collect($this->validated())->only([
            'title', 'first_name', 'last_name', 'gender', 'phone', 'alt_phone', 'whatsapp', 'email',
            'birthday', 'address', 'city', 'state', 'occupation', 'source', 'branch_id', 'assigned_to',
        ])->all();

        $partial = $this->isMethod('patch');

        if (! $partial || $this->has('is_vip')) {
            $data['is_vip'] = $this->boolean('is_vip');
        }
        if (! $partial || $this->has('preferences')) {
            $prefs = array_filter((array) $this->input('preferences', []), fn ($v) => filled($v));
            $data['preferences'] = $prefs ?: null;
        }

        return $data;
    }

    /** Tags arrive as an array or a comma-separated string. */
    public function tagNames(): array
    {
        $tags = $this->input('tags', []);

        return is_string($tags) ? explode(',', $tags) : (array) $tags;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('tags'))) {
            $this->merge(['tags' => array_values(array_filter(array_map('trim', explode(',', $this->input('tags')))))]);
        }
    }
}
