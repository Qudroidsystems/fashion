<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Messaging\CustomerUpdates;
use App\Services\Messaging\Messenger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Which updates customers get automatically, on which channels, and in what words. */
class CustomerMessagesController extends Controller
{
    public function __construct(protected CustomerUpdates $updates, protected Messenger $messenger)
    {
        $this->middleware('permission:Manage notification templates');
    }

    public function edit(): View
    {
        return view('settings.customer-messages', [
            'settings' => $this->updates->settings(),
            'available' => $this->messenger->available(),
            'samples' => collect(CustomerUpdates::EVENTS)->keys()->mapWithKeys(fn ($k) => [$k => $this->updates->sample($k)]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $events = array_keys(CustomerUpdates::EVENTS);
        $data = $request->validate([
            'events' => 'required|array',
            'events.*.on' => 'nullable|boolean',
            'events.*.text' => 'nullable|string|max:1000',
            'channels' => 'required|array|min:1',
            'channels.*' => ['nullable', Rule::in(array_keys(CustomerUpdates::CHANNEL_LABELS))],
            'first_only' => 'nullable|boolean',
            'balance_after_days' => 'required|integer|min:0|max:60',
            'balance_repeat_days' => 'required|integer|min:1|max:60',
            'balance_max' => 'required|integer|min:1|max:10',
        ]);
        $data['events'] = array_intersect_key($data['events'], array_flip($events));
        $data['channels'] = array_values(array_filter($data['channels']));
        $this->updates->saveSettings($data);

        return back()->with('success', 'Customer messages saved.');
    }
}
