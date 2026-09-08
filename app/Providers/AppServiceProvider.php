<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models;
use App\Services\Time\Clock;
use App\Support\ConfiguredUrlGenerator;
use App\View\Components\Ui\SwitchControl;
use App\View\Composers\PublicLayoutComposer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Support\ServiceProvider;

/**
 * Application-wide bootstrapping.
 *
 * @see BR-07, BR-36 · CONSTITUTION art. 6, art. 11, art. 19 · CONTRACT §5
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single source of time. Bound so it can be type-hinted and
        // resolved like any other collaborator; its API stays static, and
        // Clock::fake() remains the only way to move it in tests.
        $this->app->singleton(Clock::class);

        // BR-36 / art. 12: APP_URL is the source of every absolute link. The
        // framework's generator reads the host and scheme off the incoming
        // request instead, so it is replaced here - see
        // App\Support\ConfiguredUrlGenerator for why that is a security
        // property and not a preference. The framework's own extender (session
        // and key resolvers for signed links) still applies to this instance,
        // because rebinding an abstract does not drop its extenders.
        $this->app->singleton('url', static function ($app): ConfiguredUrlGenerator {
            $routes = $app['router']->getRoutes();

            $app->instance('routes', $routes);

            return new ConfiguredUrlGenerator(
                $routes,
                $app->rebinding('request', static function ($app, $request): void {
                    $app['url']->setRequest($request);
                }),
                $app['config']['app.asset_url'],
            );
        });
    }

    public function boot(): void
    {
        // Every date the framework hands back is immutable, matching the
        // CarbonImmutable that Clock returns. A mutable Carbon coming out of a
        // model attribute is how "the session start time changed under us"
        // bugs happen (art. 11).
        Date::use(CarbonImmutable::class);

        // A stable, storage-facing name for every model. `audit_logs.entity_type`
        // and every other polymorphic column keeps a short slug — `final_project`,
        // `evaluation` — instead of a PHP class name, so the audit trail stays
        // readable and survives a namespace move (PROJECT-CONTRACT §4; the same
        // vocabulary the EvaluationEntity enum already uses).
        Relation::morphMap([
            'assignment' => Models\Assignment::class,
            'attendance' => Models\Attendance::class,
            'audit_log' => Models\AuditLog::class,
            'certificate' => Models\Certificate::class,
            'cohort' => Models\Cohort::class,
            'digital_card' => Models\DigitalCard::class,
            'email_token' => Models\EmailToken::class,
            'enrollment' => Models\Enrollment::class,
            'evaluation' => Models\Evaluation::class,
            'final_project' => Models\FinalProject::class,
            'impersonation_session' => Models\ImpersonationSession::class,
            'journey_step' => Models\JourneyStep::class,
            'landing_setting' => Models\LandingSetting::class,
            'message' => Models\Message::class,
            'notification' => Models\Notification::class,
            'notification_preference' => Models\NotificationPreference::class,
            'profile' => Models\Profile::class,
            'program' => Models\Program::class,
            'project_submission' => Models\ProjectSubmission::class,
            'resource' => Models\Resource::class,
            'session' => Models\Session::class,
            'submission' => Models\Submission::class,
            'thread' => Models\Thread::class,
            'thread_participant' => Models\ThreadParticipant::class,
            'user' => Models\User::class,
            'user_journey_state' => Models\UserJourneyState::class,
            'week' => Models\Week::class,
        ]);

        $isProduction = $this->app->environment('production');

        // Article 19: zero N+1 queries. Outside production a lazy load, a
        // silently dropped attribute or a read of a column that was never
        // selected all throw, so the mistake surfaces on the developer's
        // machine instead of on a trainee's phone.
        Model::preventLazyLoading(! $isProduction);
        Model::preventSilentlyDiscardingAttributes(! $isProduction);
        Model::preventAccessingMissingAttributes(! $isProduction);

        // Model::unguard() and $guarded = [] are forbidden platform-wide
        // (art. 13, rule 8). Mass assignment is opt-in per model, always.

        // Refuse db:wipe, migrate:fresh and migrate:refresh against production.
        // Shared hosting gives one database and no snapshot button (art. 10).
        DB::prohibitDestructiveCommands($isProduction);

        // `switch` is a reserved word in PHP, so <x-ui.switch> cannot resolve to
        // a class called Switch. The tag is aliased to SwitchControl instead;
        // every other component in the library resolves by convention.
        Blade::component(SwitchControl::class, 'ui.switch');

        // The public shell prints meta tags and a Schema.org graph it must not
        // assemble itself (art. 13, rule 13). The composer hands it the values.
        ViewFacade::composer('layouts.public', PublicLayoutComposer::class);

        // BR-36: APP_URL is the source of every absolute link - certificate
        // verification URLs, digital-card QR targets and e-mail buttons all
        // have to resolve identically whether they were built inside a web
        // request, a queued job or an artisan command. That is enforced by the
        // generator bound in register(), in EVERY environment: the previous
        // `if (! local|testing) forceRootUrl(...)` left the rule unenforced
        // exactly where the suite could have proved it, and pinned the base at
        // boot so a later configuration change was ignored.
    }
}
