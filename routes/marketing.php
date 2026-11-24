<?php

use App\Enums\MarketingPage;
use App\Http\Controllers\Marketing\ContactController;
use App\Http\Controllers\Marketing\MarketingController;
use App\Http\Controllers\Marketing\SecurityTxtController;
use App\Http\Controllers\Marketing\SitemapController;
use Illuminate\Support\Facades\Route;

/*
| The public marketing site (spec §21, D83). Server-rendered with Inertia SSR; every page
| sets its own title, description, canonical, OG and Twitter tags.
*/

Route::get('/', [MarketingController::class, 'landing'])->name('welcome');

Route::name('marketing.')->group(function () {
    Route::get('pricing', [MarketingController::class, 'pricing'])->name('pricing');
    Route::get('features/{feature}', [MarketingController::class, 'feature'])
        ->whereIn('feature', MarketingPage::slugs())
        ->name('feature');
    Route::get('security', [MarketingController::class, 'security'])->name('security');

    Route::get('contact', [ContactController::class, 'show'])->name('contact');
    Route::post('contact', [ContactController::class, 'store'])
        ->middleware('throttle:30,1') // flood guard only; the hourly limit counts accepted messages (D119)
        ->name('contact.store');

    foreach (MarketingPage::legal() as $page) {
        Route::get($page, [MarketingController::class, 'legal'])
            ->defaults('page', $page)
            ->name('legal.'.$page);
    }
});

Route::get('sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('.well-known/security.txt', SecurityTxtController::class)->name('security-txt');
