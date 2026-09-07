<?php

declare(strict_types=1);

/**
 * Personal details. Arabic names come from Faker's ar_SA provider rather than
 * literals, because no Arabic text may appear inside a .php file
 * (Constitution art. 13 #3).
 *
 * @see PRD §7.1 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
final class ProfileFactory extends Factory
{
    /** @var class-string<Profile> */
    protected $model = Profile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = fake()->randomElement(['male', 'female']);
        $arabic = fake('ar_SA');
        $latin = fake('en_US');

        return [
            'user_id' => User::factory(),
            'first_name_ar' => $arabic->firstName($gender),
            'second_name_ar' => $arabic->firstName('male'),
            'third_name_ar' => $arabic->firstName('male'),
            'last_name_ar' => $arabic->lastName(),
            'first_name_en' => $latin->firstName($gender),
            'second_name_en' => $latin->firstName('male'),
            'third_name_en' => $latin->firstName('male'),
            'last_name_en' => $latin->lastName(),
            'phone' => '05'.fake()->unique()->numerify('########'),
            'gender' => $gender,
            'avatar_url' => null,
            'birth_date' => null,
            'city' => null,
            'education_level' => null,
            'bio' => null,
        ];
    }

    public function male(): self
    {
        return $this->state(fn (array $attributes): array => ['gender' => 'male']);
    }

    public function female(): self
    {
        return $this->state(fn (array $attributes): array => ['gender' => 'female']);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (array $attributes): array => ['user_id' => $user->getKey()]);
    }
}
