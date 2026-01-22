<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        
        // OPTIMIZED: Cache translations per locale (cleared on deploy)
        $translations = cache()->rememberForever("translations_{$locale}", function () {
            return __('messages');
        });

        return [
            ...parent::share($request),
            'translations' => $translations,
            'seo' => [
                'site_name' => config('app.name', 'HubPorn'),
                'description' => $translations['welcome']['subtitle'] ?? 'The best videos and photos in one place.',
                'keywords' => 'porn, videos, photos, adult content, hubporn',
            ],
            'locale' => $locale,
            // computed_avatar_url is now auto-appended by User model
        ];
    }
}
