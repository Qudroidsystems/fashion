<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectBudgetLine;
use App\Models\ProjectExpense;
use App\Models\ProjectMilestone;
use App\Models\ProjectPaymentSchedule;
use App\Models\ProjectUpdate;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Milestones, client updates, budget, expenses and payment schedule (FR-PRJ-002, -005, -007). */
class PlanController extends Controller
{
    public function __construct(protected ProjectService $projects)
    {
        $this->middleware('permission:Manage project', ['except' => ['publishMilestone', 'publishUpdate', 'unpublishUpdate']]);
        $this->middleware('permission:Publish project update', ['only' => ['publishMilestone', 'publishUpdate', 'unpublishUpdate']]);
    }

    /* ------------------------------ milestones ------------------------------ */

    public function storeMilestone(Request $request, Project $project): RedirectResponse
    {
        $this->projects->saveMilestone($project, null, $this->milestoneData($request));

        return back()->with('success', 'Milestone added.');
    }

    public function updateMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->own($project, $milestone);
        $action = $request->input('action');
        match ($action) {
            'done'   => $this->projects->completeMilestone($milestone, $request->user(), $request->input('notes')),
            'skip'   => $this->projects->completeMilestone($milestone, $request->user(), $request->input('notes'), skip: true),
            'reopen' => $this->projects->reopenMilestone($milestone),
            default  => $this->projects->saveMilestone($project, $milestone, $this->milestoneData($request)),
        };

        return back()->with('success', match ($action) { 'done' => 'Milestone done.', 'skip' => 'Milestone skipped.', 'reopen' => 'Milestone reopened.', default => 'Milestone saved.' });
    }

    public function destroyMilestone(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->own($project, $milestone);
        abort_if($milestone->published_at !== null, 422, 'A published milestone can be hidden but not deleted.');
        $milestone->delete();

        return back()->with('success', 'Milestone removed.');
    }

    public function publishMilestone(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->own($project, $milestone);
        $this->projects->publishMilestone($milestone, $request->user());

        return back()->with('success', 'Published — the client can see it' . ($project->customer?->email ? ' and has been emailed (if they agreed to updates).' : '.'));
    }

    protected function milestoneData(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'due_date' => 'nullable|date', 'notes' => 'nullable|string|max:500',
            'customer_visible' => 'nullable|boolean',
        ]);
        $data['customer_visible'] = $request->boolean('customer_visible');

        return $data;
    }

    /* ------------------------------- updates ------------------------------- */

    public function storeUpdate(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:160', 'body' => 'required|string|max:5000', 'customer_visible' => 'nullable|boolean',
            'images' => 'nullable|array|max:8', 'images.*' => 'image|max:5120',
        ]);
        $u = $this->projects->addUpdate($project, $data + ['customer_visible' => $request->boolean('customer_visible')], $request->file('images', []), $request->user());
        if ($request->boolean('publish') && $request->user()->can('Publish project update')) {
            $this->projects->publishUpdate($u, $request->user());

            return back()->with('success', 'Update published to the client.');
        }

        return back()->with('success', 'Update saved' . ($u->customer_visible ? '; publish it when it is ready for the client.' : ' (staff only).'));
    }

    public function publishUpdate(Request $request, Project $project, ProjectUpdate $update): RedirectResponse
    {
        $this->own($project, $update);
        $this->projects->publishUpdate($update, $request->user());

        return back()->with('success', 'Update published to the client.');
    }

    public function unpublishUpdate(Project $project, ProjectUpdate $update): RedirectResponse
    {
        $this->own($project, $update);
        $this->projects->unpublishUpdate($update);

        return back()->with('success', 'Update hidden from the client.');
    }

    /* -------------------------------- money -------------------------------- */

    public function storeBudget(Request $request, Project $project): RedirectResponse
    {
        $this->projects->addBudgetLine($project, $request->validate([
            'category' => ['required', Rule::in(array_keys(ProjectBudgetLine::CATEGORIES))], 'description' => 'nullable|string|max:160',
            'amount' => 'required|numeric|min:0|max:10000000000',
        ]));

        return back()->with('success', 'Budget line added.');
    }

    public function destroyBudget(Project $project, ProjectBudgetLine $line): RedirectResponse
    {
        $this->own($project, $line);
        $line->delete();

        return back()->with('success', 'Budget line removed.');
    }

    public function storeExpense(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(ProjectBudgetLine::CATEGORIES))], 'description' => 'required|string|max:160',
            'amount' => 'required|numeric|min:0.01|max:10000000000', 'spent_on' => 'required|date', 'paid_to' => 'nullable|string|max:120',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);
        $this->projects->addExpense($project, $data, $request->file('receipt'), $request->user());

        return back()->with('success', 'Expense recorded.');
    }

    public function destroyExpense(Project $project, ProjectExpense $expense): RedirectResponse
    {
        $this->own($project, $expense);
        $this->projects->deleteExpense($expense);

        return back()->with('success', 'Expense removed.');
    }

    public function storeSchedule(Request $request, Project $project): RedirectResponse
    {
        $this->projects->addSchedule($project, $request->validate([
            'label' => 'required|string|max:80', 'due_date' => 'required|date', 'amount' => 'required|numeric|min:1|max:10000000000',
        ]));

        return back()->with('success', 'Payment added to the schedule.');
    }

    public function destroySchedule(Project $project, ProjectPaymentSchedule $schedule): RedirectResponse
    {
        $this->own($project, $schedule);
        $schedule->delete();

        return back()->with('success', 'Removed from the schedule.');
    }

    protected function own(Project $project, $child): void
    {
        abort_unless((int) $child->project_id === $project->id, 404);
    }
}
