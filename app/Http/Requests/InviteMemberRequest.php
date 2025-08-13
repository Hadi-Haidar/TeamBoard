<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in service
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email:rfc,dns|max:255',
            'role' => 'required|in:member,viewer',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'role.in' => 'Role must be either member or viewer.',
        ];
    }
    
}
