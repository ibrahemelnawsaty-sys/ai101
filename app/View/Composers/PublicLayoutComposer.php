<?php

declare(strict_types=1);

namespace App\View\Composers;

use Illuminate\View\View;

/**
 * Everything the public shell prints in its <head>.
 *
 * The layout used to assemble its own meta tags and Schema.org graph in a raw
 * PHP island. Article 13 item 13 does not allow a template to build a value, so
 * the assembly happens here and layouts/public.blade.php only prints.
 *
 * Every value comes from config/athar.php, which reads .env, and from APP_URL -
 * never from a literal in a template (BR-36, CONSTITUTION art. 12).
 *
 * @see PRD §9.1 · BR-36 · CONSTITUTION.md Articles 12, 13, 16, 19
 */
final class PublicLayoutComposer
{
    public function compose(View $view): void
    {
        $data = $view->getData();

        $siteName = (string) __('landing.meta.brand_alt');
        $description = (string) ($data['pageDescription'] ?? '');
        $appUrl = rtrim((string) config('app.url'), '/');

        $view->with([
            'siteName' => $siteName,
            'title' => $data['pageTitle'] ?? config('athar.program_name'),
            'description' => $description,
            'url' => $data['canonical'] ?? url()->current(),
            'share' => $data['ogImage'] ?? asset('brand/og/og-base.png'),
            'graph' => $data['jsonLd'] ?? self::graph($siteName, $description, $appUrl),
        ]);
    }

    /**
     * Two linked nodes: the centre that issues the certificate, and the
     * programme it teaches.
     *
     * @return array<string, mixed>
     */
    private static function graph(string $siteName, string $description, string $appUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'EducationalOrganization',
                    '@id' => $appUrl.'/#organization',
                    'name' => $siteName,
                    'url' => 'https://'.config('athar.center_domain'),
                    'email' => config('athar.email'),
                    'logo' => asset('brand/svg/athar-wordmark-purple.svg'),
                    'sameAs' => ['https://wa.me/'.config('athar.whatsapp')],
                ],
                [
                    '@type' => 'Course',
                    '@id' => $appUrl.'/#course',
                    'name' => config('athar.program_name'),
                    'alternateName' => config('athar.program_short_name'),
                    'description' => $description,
                    'url' => $appUrl.'/',
                    'inLanguage' => 'ar',
                    'isAccessibleForFree' => true,
                    'provider' => ['@id' => $appUrl.'/#organization'],
                ],
            ],
        ];
    }
}
