<?php

namespace App\Http\Requests;

use App\Enums\CatalogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('games.manage') ?? false;
    }

    public function rules(): array
    {
        $image = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'];

        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('games', 'slug')->ignore($this->gameId()),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'provider_category_id' => ['nullable', 'string', 'max:64'],
            'validation_category_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'meta_title' => ['nullable', 'string', 'max:160'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'logo' => [...$image, 'dimensions:min_width=64,min_height=64,max_width=4000,max_height=4000'],
            'banner' => [...$image, 'dimensions:min_width=300,min_height=100,max_width=4000,max_height=4000'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_banner' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may contain only lowercase letters, numbers and single dashes.',
        ];
    }

    protected function gameId(): ?int
    {
        return null;
    }
}