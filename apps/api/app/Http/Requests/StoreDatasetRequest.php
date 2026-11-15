<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'exists:organizations,id'],
            'project_id' => ['nullable', 'exists:projects,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:20480'],
        ];
    }
}
