{{-- Reusable print letterhead: the school's own letterhead (logo +
     school name) on the left, and Classly's own icon + "CLASSLY"
     text credit (the system that generated the document) on the
     right — so the school's logo is never displaced by the app's own
     branding. Included both once at the very top of the document and
     again at the top of every per-section block, so each printed
     section stands on its own if separated from the rest (e.g.
     someone Ctrl+P's just page 3). --}}
<div class="letterhead">
    <div class="letterhead-school">
        <img src="{{ $schoolLogoUrl ?: asset('logo.png') }}" alt="School Logo">
        <div>
            <h1>{{ $schoolName }}</h1>
            <p>{{ $report['title'] ?? 'Report' }}</p>
        </div>
    </div>

    <div class="letterhead-brand">
        <img src="{{ asset('logo.png') }}" alt="Classly">
        <span>CLASSLY</span>
    </div>
</div>