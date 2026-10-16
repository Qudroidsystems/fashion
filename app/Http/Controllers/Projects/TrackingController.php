<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Services\Projects\ProjectService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Response;

/** Client progress page (SRS FR-PRJ-006, scenario 39). No login: a random, revocable, optionally expiring link. */
class TrackingController extends Controller
{
    public function __construct(protected ProjectService $projects)
    {
    }

    public function show(string $token): Response
    {
        $project = $this->projects->findByToken($token);
        abort_unless($project && $project->trackingOpen(), 404);

        return CurrentTenant::run($project->tenant, function ($house) use ($project) {
            if (! $project->tracking_viewed_at || $project->tracking_viewed_at->lt(now()->subHour())) {
                $project->forceFill(['tracking_viewed_at' => now()])->saveQuietly();
            }

            return response()->view('projects.public.track', ['house' => $house, 'view' => $this->projects->publicView($project), 'tz' => $house->timezone])
                ->header('X-Robots-Tag', 'noindex, nofollow');
        });
    }
}
