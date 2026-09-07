<?php

/**
 * Accounts and personal details.
 * A user is soft-deleted only, never hard-deleted (PRD §7.8, Constitution art. 13 #11).
 * `password_hash` is never returned in any response.
 * The Saudi phone number is unique platform-wide (PRD §7.7).
 *
 * @see PRD §7.1, §7.7, §7.8 · BR-29, BR-30, BR-32 · PROJECT-CONTRACT §4
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->string('email', 190)->unique();
            $table->string('password_hash', 255);
            $table->string('role', 20)->default('participant');
            $table->string('status', 20)->default('pending');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('locale', 5)->default('ar');
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedSmallInteger('failed_login_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'status']);
        });

        Schema::create('profiles', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('first_name_ar', 40);
            $table->string('second_name_ar', 40);
            $table->string('third_name_ar', 40);
            $table->string('last_name_ar', 40);

            $table->string('first_name_en', 40);
            $table->string('second_name_en', 40);
            $table->string('third_name_en', 40);
            $table->string('last_name_en', 40);

            $table->string('phone', 20)->unique();
            $table->string('gender', 10);
            $table->string('avatar_url', 500)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('education_level', 80)->nullable();
            $table->text('bio')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('users');
    }
};
