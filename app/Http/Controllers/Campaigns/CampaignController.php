<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CustomerTag;
use App\Models\OutgoingMessage;
use App\Services\Campaigns\CampaignService;
use App\Services\Messaging\CustomerUpdates;
use App\Services\Messaging\Messenger;
use App\Support\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Campaigns: write a promotion, choose who gets it, send now or later, watch it go out. */
class CampaignController extends Controller
{
    public function __construct(protected CampaignService $campaigns, protected Messenger $messenger)
    {
        $this->middleware('permission:Send campaign');
    }

    public function index(): View
    {
        $list = Campaign::with('creator:id,name')->latest('id')->paginate(20);
        $list->each(fn ($c) => $c->progress = $this->campaigns->progress($c));

        return view('campaigns.index', ['campaigns' => $list, 'tz' => $this->tz()]);
    }

    public function create(): View
    {
        return view('campaigns.form', [
            'tags' => CustomerTag::orderBy('name')->get(['id', 'name']),
            'available' => $this->messenger->available(),
            'tz' => $this->tz(),
        ]);
    }

    /** Live count while the form is being filled in. */
    public function audience(Request $request): JsonResponse
    {
        [$audience, $channels] = $this->audienceInput($request);

        return response()->json($this->campaigns->counts($audience, $channels));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'body' => 'required|string|max:900',
            'audience_type' => ['required', Rule::in(array_keys(Campaign::AUDIENCES))],
            'tag_ids' => 'nullable|array|required_if:audience_type,tags', 'tag_ids.*' => 'integer',
            'days' => 'nullable|integer|min:1|max:1000',
            'channels' => 'required|array|min:1', 'channels.*' => [Rule::in(array_keys(CustomerUpdates::CHANNEL_LABELS))],
            'when' => 'required|in:now,later,draft', 'send_at' => 'nullable|required_if:when,later|date|after:now',
        ]);
        [$audience] = $this->audienceInput($request);
        $c = Campaign::create([
            'name' => $data['name'], 'body' => trim($data['body']), 'channels' => array_values($data['channels']), 'audience' => $audience,
            'status' => 'draft', 'created_by' => $request->user()->id,
            'send_at' => $data['when'] === 'later' ? CarbonImmutable::parse($data['send_at'], $this->tz())->utc() : null,
        ]);
        if ($data['when'] === 'draft') {
            return redirect()->route('campaigns.show', $c)->with('success', 'Saved as a draft.');
        }
        $this->campaigns->launch($c, $request->user());

        return redirect()->route('campaigns.show', $c)->with('success', $c->status === 'scheduled'
            ? "Scheduled for {$c->recipients} customers." : "Sending to {$c->recipients} customers — a few hundred a minute.");
    }

    public function show(Campaign $campaign): View
    {
        return view('campaigns.show', [
            'c' => $campaign, 'progress' => $this->campaigns->progress($campaign), 'tz' => $this->tz(),
            'recipients' => OutgoingMessage::with('customer:id,public_id,first_name,last_name')->where('related_type', $campaign->getMorphClass())
                ->where('related_id', $campaign->id)->orderByRaw("case status when 'failed' then 0 when 'queued' then 1 else 2 end")->latest('id')->paginate(50),
            'tags' => CustomerTag::whereIn('id', (array) ($campaign->audience['tag_ids'] ?? []))->pluck('name'),
        ]);
    }

    public function send(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->campaigns->launch($campaign, $request->user());

        return back()->with('success', "Sending to {$campaign->recipients} customers.");
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        $this->campaigns->cancel($campaign);

        return back()->with('success', 'Stopped. Messages already sent can’t be called back.');
    }

    protected function audienceInput(Request $request): array
    {
        $type = $request->input('audience_type', 'all');
        $audience = array_filter([
            'type' => array_key_exists($type, Campaign::AUDIENCES) ? $type : 'all',
            'tag_ids' => $type === 'tags' ? array_map('intval', (array) $request->input('tag_ids', [])) : null,
            'days' => in_array($type, ['recent', 'lapsed'], true) ? max(1, (int) $request->input('days', 90)) : null,
        ], fn ($v) => $v !== null);

        return [$audience, array_values(array_intersect((array) $request->input('channels', []), array_keys(CustomerUpdates::CHANNEL_LABELS)))];
    }

    protected function tz(): string
    {
        return CurrentTenant::get()?->timezone ?: config('app.timezone');
    }
}
