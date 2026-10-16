<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\OrderLine;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Participants and their garments (SRS FR-PRJ-003, §6.4 step 24). */
class ParticipantController extends Controller
{
    public function __construct(protected ProjectService $projects)
    {
        $this->middleware('permission:Manage project');
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'name'       => 'required_without:bulk|nullable|string|max:120',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:160',
            'role'       => 'nullable|string|max:60',
            'group_name' => 'nullable|string|max:60',
            'pays'       => 'required|in:client,self',
            'bulk'       => 'nullable|string|max:20000',
        ]);
        if (! empty($data['bulk'])) {
            $added = $this->projects->addParticipantsFromText($project, $data['bulk'], $data['group_name'] ?? null, $data['pays']);

            return back()->with('success', $added->count() . ' participant(s) added.');
        }
        $p = $this->projects->addParticipant($project, $data);

        return back()->with('success', "{$p->name} added.");
    }

    public function update(Request $request, Project $project, ProjectParticipant $participant): RedirectResponse
    {
        abort_unless($participant->project_id === $project->id, 404);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:160',
            'role' => 'nullable|string|max:60', 'group_name' => 'nullable|string|max:60', 'pays' => 'required|in:client,self',
            'notes' => 'nullable|string|max:500',
        ]);
        $this->projects->updateParticipant($participant, $data);

        return back()->with('success', 'Saved.');
    }

    public function withdraw(Request $request, Project $project, ProjectParticipant $participant): RedirectResponse
    {
        abort_unless($participant->project_id === $project->id, 404);
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->projects->withdraw($participant, $data['reason']);

        return back()->with('success', "{$participant->name} withdrawn; their garments were taken off the order.");
    }

    /** Open (or create) their customer record and go to measuring. */
    public function measure(Project $project, ProjectParticipant $participant): RedirectResponse
    {
        abort_unless($participant->project_id === $project->id, 404);
        $customer = $this->projects->ensureCustomer($participant);

        return redirect()->route('measurements.create', $customer)->with('success', "Measuring {$participant->name} for {$project->name}.");
    }

    public function addGarments(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'participant_ids'    => 'required|array|min:1',
            'participant_ids.*'  => 'integer',
            'kind'               => 'required|in:bespoke,ready_made,service',
            'style_id'           => 'nullable|required_if:kind,bespoke|integer',
            'product_variant_id' => 'nullable|required_if:kind,ready_made|integer',
            'description'        => 'nullable|required_if:kind,service|string|max:255',
            'options'            => 'nullable|array',
            'options.*'          => 'string|max:120',
            'quantity'           => 'nullable|numeric|min:1|max:999',
            'unit_price'         => 'nullable|numeric|min:0',
            'instructions'       => 'nullable|string|max:2000',
        ]);
        $people = $project->participants()->whereIn('id', $data['participant_ids'])->get();
        $added = $this->projects->addGarments($project, $people, $data, $request->user());

        return back()->with('success', $added->count() . ' garment(s) added.');
    }

    public function removeGarment(Request $request, Project $project, OrderLine $line): RedirectResponse
    {
        abort_unless($line->order?->project_id === $project->id, 404);
        $this->projects->removeGarment($line, 'Removed from project ' . $project->number);

        return back()->with('success', 'Garment removed.');
    }

    public function linkMeasurement(Request $request, Project $project, OrderLine $line): RedirectResponse
    {
        abort_unless($line->order?->project_id === $project->id, 404);
        $data = $request->validate(['measurement_session_id' => ['nullable', 'integer']]);
        $this->projects->linkMeasurement($line, $data['measurement_session_id'] ?? null);

        return back()->with('success', 'Measurements linked.');
    }
}
