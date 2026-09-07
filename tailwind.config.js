/**
 * منصة أثر — إعداد Tailwind
 *
 * Every scale below is a *reference* to a CSS custom property declared in
 * resources/css/tokens.css.  Nothing here holds a literal colour, spacing,
 * radius, shadow or duration: the token file stays the single source of truth
 * (Constitution Article 6, Article 13 rule 4) and Gate G6 can keep grepping a
 * single file.
 *
 * RTL is handled with logical properties, so the plugin list stays empty and
 * the `space-*` / `divide-*` utilities are avoided in favour of `gap-*`.
 *
 * @see CONSTITUTION Articles 6, 9, 13, 16 · PROJECT-CONTRACT §12 · PRD §5
 */

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/View/Components/**/*.php',
        './app/Http/Controllers/**/*.php',
        './lang/**/*.php',
    ],

    // Logical properties everywhere; Tailwind's physical helpers are not used.
    corePlugins: {
        float: false,
        clear: false,
    },

    theme: {
        extend: {
            colors: {
                violet: {
                    900: 'var(--violet-900)',
                    700: 'var(--violet-700)',
                    600: 'var(--violet-600)',
                    500: 'var(--violet-500)',
                    400: 'var(--violet-400)',
                    300: 'var(--violet-300)',
                    100: 'var(--violet-100)',
                    50: 'var(--violet-050)',
                },
                teal: {
                    800: 'var(--teal-800)',
                    700: 'var(--teal-700)',
                    600: 'var(--teal-600)',
                    500: 'var(--teal-500)',
                    300: 'var(--teal-300)',
                    100: 'var(--teal-100)',
                },
                ink: {
                    DEFAULT: 'var(--ink)',
                    2: 'var(--ink-2)',
                    3: 'var(--ink-3)',
                    950: 'var(--ink-950)',
                    900: 'var(--ink-900)',
                    850: 'var(--ink-850)',
                    800: 'var(--ink-800)',
                    700: 'var(--ink-700)',
                    600: 'var(--ink-600)',
                },
                n: {
                    900: 'var(--n-900)',
                    600: 'var(--n-600)',
                    500: 'var(--n-500)',
                    400: 'var(--n-400)',
                    200: 'var(--n-200)',
                    150: 'var(--n-150)',
                    100: 'var(--n-100)',
                    0: 'var(--n-000)',
                },
                ok: { DEFAULT: 'var(--ok)', 700: 'var(--ok-700)', 100: 'var(--ok-100)' },
                // Burnt orange only. There is no yellow and no gold anywhere.
                warn: { DEFAULT: 'var(--warn)', 700: 'var(--warn-700)', 300: 'var(--warn-300)', 100: 'var(--warn-100)' },
                bad: { DEFAULT: 'var(--bad)', 700: 'var(--bad-700)', 300: 'var(--bad-300)', 100: 'var(--bad-100)' },

                // Semantic roles — these flip with data-surface.
                surface: {
                    page: 'var(--surface-page)',
                    1: 'var(--surface-1)',
                    2: 'var(--surface-2)',
                    3: 'var(--surface-3)',
                    inset: 'var(--surface-inset)',
                    raised: 'var(--surface-raised)',
                },
                content: {
                    strong: 'var(--text-strong)',
                    body: 'var(--text-body)',
                    muted: 'var(--text-muted)',
                    subtle: 'var(--text-subtle)',
                    faint: 'var(--text-faint)',
                    accent: 'var(--text-accent)',
                    invert: 'var(--text-invert)',
                },
                line: {
                    1: 'var(--border-1)',
                    2: 'var(--border-2)',
                    3: 'var(--border-3)',
                    accent: 'var(--border-accent)',
                },
            },

            spacing: {
                1: 'var(--s1)',
                2: 'var(--s2)',
                3: 'var(--s3)',
                4: 'var(--s4)',
                5: 'var(--s5)',
                6: 'var(--s6)',
                8: 'var(--s8)',
                10: 'var(--s10)',
                12: 'var(--s12)',
                16: 'var(--s16)',
                24: 'var(--s24)',
                touch: 'var(--touch)',
                header: 'var(--header-h)',
                nav: 'var(--nav-h)',
                side: 'var(--side-w)',
                'side-collapsed': 'var(--side-w-collapsed)',
            },

            borderRadius: {
                sm: 'var(--r-sm)',
                md: 'var(--r-md)',
                lg: 'var(--r-lg)',
                xl: 'var(--r-xl)',
                full: 'var(--r-full)',
            },

            boxShadow: {
                sm: 'var(--sh-sm)',
                md: 'var(--sh-md)',
                lg: 'var(--sh-lg)',
                glow: 'var(--sh-glow)',
                focus: 'var(--sh-focus)',
            },

            fontFamily: {
                sans: 'var(--font)',
                display: 'var(--font-display)',
                mono: 'var(--font-mono)',
            },

            fontSize: {
                h1: ['var(--fs-h1)', { lineHeight: 'var(--lh-h1)' }],
                h2: ['var(--fs-h2)', { lineHeight: 'var(--lh-h2)' }],
                h3: ['var(--fs-h3)', { lineHeight: 'var(--lh-h3)' }],
                h4: ['var(--fs-h4)', { lineHeight: 'var(--lh-h4)' }],
                body: ['var(--fs-body)', { lineHeight: 'var(--lh-body)' }],
                sm: ['var(--fs-sm)', { lineHeight: 'var(--lh-sm)' }],
                xs: ['var(--fs-xs)', { lineHeight: 'var(--lh-xs)' }],
                '2xs': ['var(--fs-2xs)', { lineHeight: 'var(--lh-xs)' }],
                stat: ['var(--fs-stat)', { lineHeight: 'var(--lh-stat)' }],
            },

            fontWeight: {
                normal: 'var(--fw-regular)',
                medium: 'var(--fw-medium)',
                semibold: 'var(--fw-semi)',
                bold: 'var(--fw-bold)',
            },

            maxWidth: {
                shell: 'var(--maxw)',
                content: 'var(--maxw-content)',
                stage: 'var(--maxw-stage)',
                measure: 'var(--measure)',
            },

            transitionDuration: {
                fast: 'var(--t-fast)',
                base: 'var(--t-base)',
                screen: 'var(--t-screen)',
                slow: 'var(--t-slow)',
                bar: 'var(--t-bar)',
            },

            transitionTimingFunction: {
                out: 'var(--e-out)',
                io: 'var(--e-io)',
                draw: 'var(--e-draw)',
            },

            zIndex: {
                raised: 'var(--z-raised)',
                sticky: 'var(--z-sticky)',
                header: 'var(--z-header)',
                drawer: 'var(--z-drawer)',
                overlay: 'var(--z-overlay)',
                float: 'var(--z-float)',
                modal: 'var(--z-modal)',
                toast: 'var(--z-toast)',
            },
        },
    },

    plugins: [],
};
