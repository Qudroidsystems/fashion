@extends('layouts.master')

@section('content')
<div class="main-content">
<div class="page-content">
<div class="container-fluid">
    @php $isNew = ! $project->exists; $sym = \App\Support\Money::symbol(); @endphp
    <x-cb.hero :title="$isNew ? 'New project' : 'Edit ' . $project->name" icon="ri-heart-2-line"
               :subtitle="$isNew ? 'The client is the person who signs and pays the main bill. You add the people being dressed next.' : 'Changing the dates updates the project’s orders (confirmed ones keep a revision).'"
               :back="$isNew ? route('projects.index') : route('projects.show', $project)" :back-label="$isNew ? 'Projects' : 'Back to project'" />
    @include('inventory.partials.flash')

    <form method="POST" action="{{ $isNew ? route('projects.store') : route('projects.update', $project) }}">
        @csrf @unless($isNew) @method('PUT') @endunless
        <div class="row g-3">
            <div class="col-xl-8">
                <x-cb.card title="Project" icon="ri-information-line">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Name *</label><input name="name" class="form-control" required maxlength="160" value="{{ old('name', $project->name) }}" placeholder="e.g. Amaka & Tunde’s wedding"></div>
                        <div class="col-md-4"><label class="form-label">Kind *</label>
                            <select name="type" class="form-select">@foreach(\App\Models\Project::TYPES as $k => $l)<option value="{{ $k }}" @selected(old('type', $project->type) === $k)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-md-8"><label class="form-label">Client *</label>
                            <select name="customer_id" class="form-select" required @disabled(! $isNew && $project->orders()->exists())><option value="">Choose…</option>
                                @foreach($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id', $project->customer_id) == $c->id)>{{ $c->name }} · {{ $c->customer_number }}{{ $c->phone ? ' · ' . $c->phone : '' }}</option>@endforeach</select>
                            @if(! $isNew && $project->orders()->exists())<input type="hidden" name="customer_id" value="{{ $project->customer_id }}"><div class="form-text">The client can’t change once garments are on their order.</div>
                            @else<div class="form-text">New client? <a href="{{ route('customers.create') }}">Add them first</a>.</div>@endif</div>
                        <div class="col-md-4"><label class="form-label">Project manager</label>
                            <select name="manager_id" class="form-select"><option value="">—</option>@foreach($staff as $s)<option value="{{ $s->id }}" @selected(old('manager_id', $project->manager_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                        <div class="col-md-4"><label class="form-label">Event date</label><input type="date" name="event_date" class="form-control" value="{{ old('event_date', $project->event_date?->toDateString()) }}"></div>
                        <div class="col-md-4"><label class="form-label">Hand over by</label><input type="date" name="delivery_date" class="form-control" value="{{ old('delivery_date', $project->delivery_date?->toDateString()) }}">
                            <div class="form-text">Becomes the orders’ promise date (defaults to the event date).</div></div>
                        <div class="col-md-4"><label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select">@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $project->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                        <div class="col-12"><label class="form-label">Venue</label><input name="venue" class="form-control" maxlength="255" value="{{ old('venue', $project->venue) }}"></div>
                        <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3" maxlength="2000">{{ old('description', $project->description) }}</textarea></div>
                        <div class="col-12"><label class="form-label">Message on the client’s progress page</label><textarea name="client_message" class="form-control" rows="2" maxlength="1000" placeholder="e.g. We are so excited to dress your family!">{{ old('client_message', $project->client_message) }}</textarea></div>
                        <div class="col-12"><label class="form-label">Internal notes</label><textarea name="internal_notes" class="form-control" rows="2" maxlength="2000">{{ old('internal_notes', $project->internal_notes) }}</textarea></div>
                    </div>
                </x-cb.card>
            </div>
            <div class="col-xl-4">
                <x-cb.card title="Agreement" icon="ri-file-text-line">
                    <label class="form-label">Contract amount</label>
                    <div class="input-group mb-1"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="contract_amount" class="form-control" value="{{ old('contract_amount', $project->contract_amount ?: '') }}"></div>
                    <div class="form-text mb-3">What was agreed in total. The project compares it with the garments you add; billing follows the garments.</div>
                    <label class="form-label">Deposit</label>
                    <div class="input-group mb-1"><span class="input-group-text">{{ $sym }}</span><input type="number" step="0.01" min="0" name="deposit_amount" class="form-control" value="{{ old('deposit_amount', $project->deposit_amount ?: '') }}"></div>
                    <div class="form-text">Required on the client’s order.</div>
                    @if($isNew)
                        <label class="d-flex gap-2 mt-3"><input type="hidden" name="starter_milestones" value="0"><input type="checkbox" name="starter_milestones" value="1" checked> Add the usual milestones for this kind of project</label>
                    @endif
                    <button class="action-btn btn-primary-cb w-100 justify-content-center py-2 mt-3"><i class="ri-save-line"></i>{{ $isNew ? 'Create project' : 'Save' }}</button>
                </x-cb.card>
            </div>
        </div>
    </form>
</div>
</div>
</div>
@endsection
