<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\CohortStatus;
use App\Enums\EmailTokenType;
use App\Enums\Gender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\AccountRegistered;
use App\Http\Controllers\Auth\Concerns\IssuesEmailTokens;
use App\Http\Controllers\Auth\Concerns\PasswordMeterCopy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Options;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Creating an account (PRD §9.2).
 *
 * Every field rule lives in RegisterRequest and is enforced on the server, not
 * in the browser. The account is created `pending` with an unverified address,
 * and an activation link valid for 24 hours and one use is issued; the
 * enrolment, the digital card and the journey are built when that link is
 * followed, not before (PRD §9.2.3).
 *
 * @see BR-30, BR-31, BR-36 · PRD §9.2 · CONSTITUTION Art. 5, Art. 11
 */
final class RegisterController extends Controller
{
    use IssuesEmailTokens;
    use PasswordMeterCopy;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): View
    {
        return view('auth.register', [
            'state' => 'ok',
            // Built here, not in the view: a multi-line array inside a
            // `:attr="[...]"` binding is silently truncated by Blade's attribute
            // parser, which is what made this page a 500 (CONSTITUTION art. 5).
            'genderOptions' => Options::fromEnum(Gender::class),
            'registerCopy' => $this->clientCopy(),
            'registrationOpen' => $this->openCohort() !== null,
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        // The switch is re-read here, not trusted from the rendered form: a
        // cohort can close between the page loading and the form arriving.
        if ($this->openCohort() === null) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->withErrors(['email' => __('auth.register.closed_body')]);
        }

        $user = DB::transaction(function () use ($request): User {
            /** @var User $user */
            $user = User::query()->create([
                'email' => (string) $request->validated('email'),
                'password_hash' => (string) $request->validated('password'),
                'role' => UserRole::Participant->value,
                'status' => UserStatus::Pending->value,
                'email_verified_at' => null,
                'locale' => (string) config('athar.locales.default', 'ar'),
                'failed_login_count' => 0,
                'locked_until' => null,
            ]);

            Profile::query()->create(array_merge(
                $request->profileAttributes(),
                ['user_id' => $user->getKey()],
            ));

            $this->audit->log(
                action: 'account.registered',
                entity: $user,
                before: null,
                after: ['email' => (string) $user->getAttribute('email')],
                actor: $user,
            );

            return $user;
        });

        $this->issueToken($user, EmailTokenType::Verify);

        AccountRegistered::dispatch($user);

        return redirect()
            ->route('login')
            ->with('status', __('auth.register.check_your_inbox', [
                'email' => (string) $user->getAttribute('email'),
            ]));
    }

    /**
     * The cohort currently accepting registrations, if any. Capacity and the
     * closing instant are both checked against the server clock (BR-07).
     */

    /**
     * Copy the wizard needs on the client: the strength meter, the rule
     * ticks and the step counter.
     *
     * Assembled here rather than inside `@json([...])` in the view. Blade's
     * directive parser mangles a nested multi-line array argument — it closed
     * the array with a parenthesis and emitted invalid PHP, which is what made
     * GET /register a 500 in production while every existing test, none of
     * which rendered the page, stayed green.
     *
     * @return array<string, mixed>
     */
    private function clientCopy(): array
    {
        return [
            'errors' => __('auth.register.errors'),
            ...$this->passwordMeterCopy(),
            'step_of' => __('auth.register.step_of', ['current' => '{current}', 'total' => '{total}']),
        ];
    }

    private function openCohort(): ?Cohort
    {
        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()
            ->with('landingSetting')
            ->where('status', CohortStatus::Open->value)
            ->orderBy('start_date')
            ->first();

        if ($cohort === null) {
            return null;
        }

        $setting = $cohort->landingSetting;

        if ($setting instanceof LandingSetting && ! (bool) $setting->getAttribute('is_registration_open')) {
            return null;
        }

        if ($cohort->seatsRemaining() <= 0) {
            return null;
        }

        $closesAt = $cohort->registration_closes_at;

        if ($closesAt !== null && Clock::now()->greaterThanOrEqualTo(Clock::toUtc($closesAt))) {
            return null;
        }

        return $cohort;
    }
}
