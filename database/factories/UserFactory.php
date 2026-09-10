<?php

declare(strict_types=1);

/**
 * Accounts for tests and seed data.
 * Time comes only from Clock (Constitution art. 11). The bcrypt hash is
 * computed once per process — at cost 12 it is far too slow to repeat 60 times.
 *
 * @see PRD §7.1 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /** @var class-string<User> */
    protected $model = User::class;

    /** The shared hash of the well-known development password. */
    private static ?string $passwordHash = null;

    /** The password every seeded account uses locally. Never used in production. */
    public const DEFAULT_PASSWORD = 'Athar#2026';

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => self::$passwordHash ??= Hash::make(self::DEFAULT_PASSWORD),
            'role' => 'participant',
            'status' => 'active',
            'email_verified_at' => Clock::now(),
            'locale' => 'ar',
            'last_login_at' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
            // The invitation columns, for the same reason the deleted_at note
            // below gives: the row gets the database default, but the in-memory
            // model the factory hands back does not know about it. And
            // preventAccessingMissingAttributes turns "does not know" into a
            // thrown MissingAttributeException — which RequirePasswordChange
            // triggers on the very first request of any test that signs a user
            // in, because the middleware reads must_change_password on exactly
            // that instance. Twenty-one attendance rules failed this way, each
            // check-in answering 500, with nothing wrong in attendance at all.
            'must_change_password' => false,
            'temp_password_expires_at' => null,
            'invited_at' => null,
            // The row this factory writes has deleted_at NULL, so the model it
            // hands back must say so too. Without it the in-memory instance is
            // missing a column the database has, and User::isActive() - which
            // reads deleted_at (art. 13 §11, soft delete only) - throws under
            // Model::preventAccessingMissingAttributes(). A fixture must be
            // indistinguishable from a row read back.
            'deleted_at' => null,
        ];
    }

    public function admin(): self
    {
        return $this->state(fn (array $attributes): array => ['role' => 'admin']);
    }

    public function trainer(): self
    {
        return $this->state(fn (array $attributes): array => ['role' => 'trainer']);
    }

    public function participant(): self
    {
        return $this->state(fn (array $attributes): array => ['role' => 'participant']);
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'suspended']);
    }

    public function unverified(): self
    {
        return $this->state(fn (array $attributes): array => ['email_verified_at' => null]);
    }

    /**
     * Temporarily locked out after repeated failed sign-ins.
     */
    public function locked(int $minutes = 15): self
    {
        return $this->state(fn (array $attributes): array => [
            'failed_login_count' => 5,
            'locked_until' => Clock::now()->addMinutes($minutes),
        ]);
    }
}
