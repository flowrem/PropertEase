<?php

namespace App\Http\Requests;

use App\Enums\PropertyType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrowseListingsRequest extends FormRequest
{
    /**
     * The public browse page is open to guests.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string|Rule>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(PropertyType::class)],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }
}
