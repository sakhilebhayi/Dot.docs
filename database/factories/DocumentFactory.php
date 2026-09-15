<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'content' => '<p>Hello</p>',
            'content_json' => ['type' => 'doc', 'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []], 'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => 'aaaaaaa1'], 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ]],
            'owner_id' => User::factory(),
            'version' => 1,
        ];
    }
}
