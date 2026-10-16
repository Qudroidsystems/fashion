{{-- A garment picture, or a gown silhouette in the house colour when there is none. --}}
@if(! empty($src))
    <img src="{{ $src }}" alt="{{ $alt ?? '' }}" loading="lazy">
@else
    <div class="ph" aria-hidden="true"><svg viewBox="-160 -70 320 520"><path fill="var(--accent)" opacity=".75" d="M-70 0 C-55 20 -30 30 0 30 C30 30 55 20 70 0 L78 58 C74 93 66 118 62 140 C92 218 132 318 150 428 Q120 446 90 428 Q60 446 30 428 Q0 446 -30 428 Q-60 446 -90 428 Q-120 446 -150 428 C-132 318 -92 218 -62 140 C-66 118 -74 93 -78 58 Z"/><path d="M-62 140 C-30 150 30 150 62 140" stroke="#fff" stroke-width="6" fill="none" opacity=".8"/><ellipse cx="0" cy="-34" rx="22" ry="26" fill="var(--accent)" opacity=".45"/></svg></div>
@endif
