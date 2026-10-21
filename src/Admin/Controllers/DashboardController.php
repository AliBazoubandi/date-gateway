<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Auth;
use Admin\View;

/**
 * GET /admin — the Control Room overview. First paint is server-rendered
 * straight from OverviewData::all(); the page then polls /admin/api/state
 * which re-serializes the very same array, so markup and polling stay in
 * lockstep.
 */
final class DashboardController
{
    public static function get(): void
    {
        Auth::require();
        View::render('overview', ['state' => OverviewData::all()], 'Overview', 'overview');
    }
}
