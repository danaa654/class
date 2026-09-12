@php
    $termLabel = trim(($term->schoolYear?->name ?? '').' · '.($term->semester?->name ?? ''));

    // `days` is stored as a comma-separated string ("Mon,Tue"), not an
    // array — counting it with is_array() always fell through to 1,
    // and Carbon's diffInMinutes() returns a *signed* diff in Carbon 3
    // (the Laravel 12 default), so start/end could come out negative
    // depending on argument order. Both together produced the "-8.0"
    // total seen before this fix.
    $totalHours = 0;
    foreach ($rows as $row) {
        foreach ($row['schedules'] ?? [] as $schedule) {
            if (!empty($schedule['start_time']) && !empty($schedule['end_time'])) {
                $start = \Carbon\Carbon::parse($schedule['start_time']);
                $end = \Carbon\Carbon::parse($schedule['end_time']);
                $dayCount = count(array_filter(explode(',', (string) ($schedule['days'] ?? ''))));
                $totalHours += abs($end->diffInMinutes($start)) / 60 * max($dayCount, 1);
            }
        }
    }

    // Sum of each row's credit Units (the "Load" column) — a separate
    // figure from $totalHours above: Units are the subject's fixed
    // curriculum credit value, while $totalHours is the faculty's
    // actual weekly contact-hour total derived from schedule times.
    // Both are shown in the footer so this PDF matches the Faculty
    // Workload tab's "Assigned Subjects" Units total as well as
    // reflecting real weekly teaching hours — one is not a substitute
    // for the other.
    $totalUnits = 0;
    foreach ($rows as $row) {
        $totalUnits += (float) ($row['units'] ?? 0);
    }
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 28px 34px; }

        .classly-brand { margin-bottom: 6px; }
        .classly-brand span { font-size: 11px; font-weight: 800; letter-spacing: 0.08em; color: #2563eb; }

        .letterhead { width: 100%; border-bottom: 2px solid #1e293b; padding-bottom: 12px; margin-bottom: 16px; }
        .letterhead img { height: 56px; width: 56px; }
        .letterhead h1 { margin: 0; font-size: 17px; font-weight: 800; letter-spacing: 0.02em; color: #1e293b; }
        .letterhead p { margin: 2px 0 0; font-size: 11px; color: #64748b; }

        table.meta { width: 100%; margin: 0 0 16px; }
        table.meta td { padding: 3px 0; font-size: 12px; }
        table.meta .label { color: #64748b; width: 140px; font-weight: 600; }

        table.schedule { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.schedule thead th {
            background: #1e293b; color: #fff; text-align: left;
            font-size: 10px; text-transform: uppercase; letter-spacing: 0.03em;
            padding: 7px 8px;
        }
        table.schedule tbody td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; vertical-align: top; }
        table.schedule tbody tr:nth-child(even) { background: #f8fafc; }
        .subject-code { font-weight: 700; color: #1e293b; }
        .subject-title { font-size: 9.5px; color: #64748b; }
        .schedule-lines div + div { margin-top: 6px; }

        .totals { margin-top: 14px; font-size: 11.5px; text-align: right; font-weight: 700; color: #1e293b; }

        /* Faculty schedule sign-off — table-based (not flex) because
           DomPDF's CSS support doesn't reliably handle flexbox; this
           mirrors the print view's 3-column "Confirmed / Noted /
           Approved by" layout using a plain table instead. */
        table.signoff { width: 100%; margin-top: 34px; table-layout: fixed; }
        table.signoff td { vertical-align: top; padding: 0 10px; font-size: 10.5px; }
        table.signoff td:first-child { padding-left: 0; }
        table.signoff td:last-child { padding-right: 0; }
        .signoff-label { color: #94a3b8; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 24px; }
        .signoff-entry { margin-bottom: 18px; }
        .signoff-name { border-top: 1px solid #334155; padding-top: 4px; margin: 0; font-weight: 700; color: #1e293b; }
        .signoff-role { font-size: 9px; color: #64748b; margin: 1px 0 0; }

        .footer { margin-top: 24px; font-size: 9px; color: #94a3b8; text-align: right; }
    </style>
</head>
<body>
    <div class="classly-brand"><span>CLASSLY</span></div>

    <table class="letterhead">
        <tr>
            <td style="width:64px;">
                @if($schoolLogoDataUri)
                    <img src="{{ $schoolLogoDataUri }}" alt="School Logo">
                @endif
            </td>
            <td>
                <h1>{{ $schoolName }}</h1>
                <p>Faculty Teaching Schedule</p>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr><td class="label">Faculty</td><td>{{ $faculty->full_name }}</td></tr>
        <tr><td class="label">Faculty ID</td><td>{{ $faculty->faculty_id }}</td></tr>
        <tr><td class="label">College</td><td>{{ $faculty->college?->name ?? '—' }}</td></tr>
        <tr><td class="label">Academic Term</td><td>{{ $termLabel }}</td></tr>
    </table>

    <table class="schedule">
        <thead>
            <tr>
                <th>EDP Code</th>
                <th>Subject</th>
                <th>Section</th>
                <th>Schedule</th>
                <th>Room</th>
                <th>Load</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['edp_code'] ?? '—' }}</td>
                    <td>
                        <div class="subject-code">{{ $row['subject_code'] ?? '—' }}</div>
                        <div class="subject-title">{{ $row['subject_title'] ?? '—' }}</div>
                    </td>
                    <td>{{ $row['section'] ?? '—' }}</td>
                    <td>
                        @if(!empty($row['schedules']))
                            <div class="schedule-lines">
                                @foreach($row['schedules'] as $schedule)
                                    <div>
                                        @if(!empty($schedule['days']) && !empty($schedule['start_time']) && !empty($schedule['end_time']))
                                            {{ $schedule['days'] }} · {{ \Carbon\Carbon::parse($schedule['start_time'])->format('g:i A') }}–{{ \Carbon\Carbon::parse($schedule['end_time'])->format('g:i A') }}
                                        @else
                                            —
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if(!empty($row['schedules']))
                            <div class="schedule-lines">
                                @foreach($row['schedules'] as $schedule)
                                    <div>{{ $schedule['room'] ?? '—' }}</div>
                                @endforeach
                            </div>
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $row['units'] ?? '—' }} Units</td>
                </tr>
            @empty
                <tr><td colspan="6">No schedule rows found.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals">
        Total Units: {{ number_format($totalUnits, 1) }}
        &nbsp;&nbsp;|&nbsp;&nbsp;
        Total Teaching Hours: {{ number_format($totalHours, 1) }}
    </div>

    <table class="signoff">
        <tr>
            <td style="width: 34%;">
                <p class="signoff-label">Confirmed by</p>
                <p class="signoff-name">{{ $faculty->full_name }}</p>
                <p class="signoff-role">Faculty</p>
            </td>
            <td style="width: 33%;">
                <p class="signoff-label">Noted by</p>
                @forelse(($deans ?? []) as $dean)
                    <div class="signoff-entry">
                        <p class="signoff-name">{{ $dean['name'] }}</p>
                        <p class="signoff-role">{{ $dean['role'] }}, {{ $dean['college'] }}</p>
                    </div>
                @empty
                    <div class="signoff-entry">
                        <p class="signoff-name">&nbsp;</p>
                        <p class="signoff-role">Dean</p>
                    </div>
                @endforelse
            </td>
            <td style="width: 33%;">
                <p class="signoff-label">Approved by</p>
                @forelse(($approvers ?? []) as $approver)
                    <div class="signoff-entry">
                        <p class="signoff-name">{{ $approver['name'] }}</p>
                        <p class="signoff-role">{{ $approver['role'] }}</p>
                    </div>
                @empty
                    <div class="signoff-entry">
                        <p class="signoff-name">&nbsp;</p>
                        <p class="signoff-role">Registrar</p>
                    </div>
                @endforelse
            </td>
        </tr>
    </table>

    <div class="footer">Generated: {{ now()->format('F j, Y g:i A') }}</div>
</body>
</html>