<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboundEmail>
 */
class InboundEmailFactory extends Factory
{
    protected $model = InboundEmail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => '<'.$this->faker->uuid().'@example.com>',
            'from' => $this->faker->name().' <'.$this->faker->safeEmail().'>',
            'subject' => $this->faker->sentence(),
            'body_plain' => $this->faker->paragraph(),
            'body_html' => '<p>'.$this->faker->sentence().'</p>',
            'received_at' => now(),
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => [
                'recipient' => 'oos@crockenhill.org',
            ],
        ];
    }
}
