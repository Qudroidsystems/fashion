@extends('sites.layout')
@section('title', $title)

@section('body')
    @include('sites.partials.page-title', ['title' => $title])
    <section class="flat-spacing">
        <div class="container" style="max-width:820px">
            @if($text)
                <div class="store-prose">{!! nl2br(e($text)) !!}</div>
            @else
                <p class="text-secondary">{{ $house->name }} has not published this page yet. <a href="{{ $base }}/contact" class="link">Ask us</a> and we will help.</p>
            @endif
        </div>
    </section>
@endsection
