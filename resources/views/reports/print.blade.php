<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $report['title'] ?? 'Report' }} — {{ $schoolName }}</title>
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 32px 40px;
            font-size: 12px;
        }

        /* ---- Letterhead ---- */
        .letterhead {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            border-bottom: 2px solid #1e293b;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .letterhead-school {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .letterhead-school img {
            height: 56px;
            width: 56px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .letterhead h1 {
            margin: 0;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: 0.02em;
            color: #1e293b;
        }

        .letterhead p {
            margin: 2px 0 0;
            font-size: 11px;
            color: #64748b;
        }

        /* ---- Report meta (Academic Year / Semester / Section) ---- */
        .meta {
            margin-bottom: 18px;
        }

        .meta p {
            margin: 2px 0;
            font-size: 12.5px;
        }

        .meta p strong {
            display: inline-block;
            min-width: 110px;
            font-weight: 600;
            color: #334155;
        }

        /* ---- Table ---- */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            background: #1e293b;
            color: #ffffff;
            text-align: left;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 7px 8px;
        }

        tbody td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11.5px;
            vertical-align: top;
        }

        tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        /* ---- Subject cell: code + title stacked, same as the Faculty
           Workload tab's Assigned Subjects table ---- */
        .subject-code {
            font-weight: 700;
            color: #1e293b;
        }

        .subject-title {
            font-size: 10.5px;
            color: #64748b;
        }

        /* ---- Multiple Schedule/Room lines under one EDP Code row
           (Face-to-Face + Online split, etc.) — same "one row, several
           Schedule lines" layout as the Workload tab. ---- */
        .schedule-lines {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        /* ---- Classly text credit (now lives at the right of the
           letterhead row, next to the app's own icon — see
           .letterhead-brand below) ---- */
        .letterhead-brand {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .letterhead-brand img {
            height: 20px;
            width: 20px;
            object-fit: contain;
        }

        .letterhead-brand span {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            color: #2563eb;
        }

        /* ---- Per-section print header (program / term / section) ---- */
        .section-header {
            margin-bottom: 10px;
        }

        .section-header .program {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
        }

        .section-header .term {
            margin: 2px 0 0;
            font-size: 11px;
            color: #64748b;
        }

        .section-heading {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 4px;
        }

        .section-block + .section-block {
            margin-top: 20px;
        }

        .empty {
            text-align: center;
            padding: 24px 0;
            color: #94a3b8;
            font-style: italic;
        }

        .footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 10px;
            color: #94a3b8;
        }

        .footer-left {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .footer-registrar {
            color: #64748b;
            font-weight: 600;
        }

        /* Faculty schedule sign-off — "Confirmed by" (the faculty
           member themselves) on the left, "Noted by" (the Dean/OIC of
           every College the faculty has a subject under) on the right.
           Only rendered for schedule_by_faculty, both for a single
           faculty and for each per-faculty page-break block below. */
        .signoff {
            margin-top: 48px;
            display: flex;
            justify-content: space-between;
            gap: 32px;
            page-break-inside: avoid;
        }

        .signoff-col {
            flex: 1;
            font-size: 11px;
        }

        .signoff-label {
            color: #94a3b8;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 28px;
        }

        .signoff-name {
            border-top: 1px solid #334155;
            padding-top: 4px;
            font-weight: 600;
            color: #1e293b;
        }

        .signoff-role {
            font-size: 10px;
            color: #64748b;
            margin-top: 1px;
        }

        .signoff-entry {
            margin-bottom: 22px;
        }

        .signoff-entry:last-child {
            margin-bottom: 0;
        }

        /* ---- Grid (weekly-timetable) print — same 30-min-row layout
           as Reports/Index.vue's on-screen Grid view (RoomGrid.vue-style
           read-only mirror), built server-side by
           ReportsService::buildGridData() so it never drifts from what
           was actually on screen when Print was clicked. An HTML table
           with rowspan (rather than a CSS grid) since that's what prints
           most reliably across browsers. ---- */
        .grid-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .grid-table th,
        .grid-table td {
            border: 1px solid #cbd5e1;
        }

        .grid-table thead th {
            background: #1e293b;
            color: #ffffff;
            text-align: center;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            padding: 6px 4px;
        }

        .grid-table thead th.grid-corner {
            width: 92px;
        }

        .grid-time-cell {
            padding: 3px 6px;
            font-size: 9px;
            font-weight: 600;
            color: #475569;
            background: #f8fafc;
            white-space: nowrap;
            vertical-align: middle;
        }

        .grid-block-cell {
            padding: 4px 6px;
            vertical-align: top;
            background: #ecfdf5;
        }

        .grid-block-cell.online {
            background: #eff6ff;
        }

        .grid-block-subject {
            font-weight: 700;
            font-size: 10px;
            color: #065f46;
        }

        .grid-block-cell.online .grid-block-subject {
            color: #1d4ed8;
        }

        .grid-block-line {
            font-size: 9.5px;
            color: #334155;
            margin-top: 1px;
        }

        .grid-empty-cell {
            padding: 0;
        }

        @media print {
            body { padding: 0 24px; }
            @page { margin: 18mm 14mm; }
        }
    </style>
    @if($gridData)
        {{-- Landscape only for the Grid print — every other report type
             on this same blade (Study Load, faculty tables, etc.) stays
             portrait, so this is scoped to its own <style> tag rather
             than folded into the unconditional @page rule above. --}}
        <style>
            @media print {
                @page { size: landscape; }
            }
        </style>
    @endif
</head>
<body>

    @include('reports.partials.letterhead')

    <div class="meta">
        @if($academicYear && empty($report['groups']))
            <p><strong>Academic Year:</strong> {{ $academicYear }}</p>
        @endif
        @if($semester && empty($report['groups']))
            <p><strong>Semester:</strong> {{ $semester }}</p>
        @endif
        @if($sectionLabel)
            <p><strong>Section:</strong> {{ $sectionLabel }}</p>
        @endif
    </div>

    @if(! $report || empty($report['rows']) || count($report['rows']) === 0)

        <p class="empty">{{ $report['empty_message'] ?? 'No data found for the selected filters.' }}</p>

    @elseif($gridData)

        {{-- Grid (weekly-timetable) print — mirrors Reports/Index.vue's
             on-screen Grid view, built from ReportsService::buildGridData().
             $gridBlocksByCell/$gridCovered pre-index the blocks by
             [day][hour-row index] so each day column can either start a
             rowspan'd block, skip a cell already covered by one, or
             render an empty slot — same "one block, several covered
             rows" shape RoomGrid.vue's own read-only mirror uses. --}}
        @php
            $gridBlocksByCell = [];
            $gridCovered = [];
            foreach ($gridData['blocks'] as $block) {
                $gridBlocksByCell[$block['day']][$block['startIndex']] = $block;
                for ($i = $block['startIndex'] + 1; $i < $block['startIndex'] + $block['span']; $i++) {
                    $gridCovered[$block['day']][$i] = true;
                }
            }
        @endphp

        <h2 class="section-heading">
            {{ $reportType === 'schedule_by_room' ? $roomLabel : ($report['facultyMeta']['full_name'] ?? '') }}
        </h2>

        <table class="grid-table">
            <thead>
                <tr>
                    <th class="grid-corner"></th>
                    @foreach($gridData['days'] as $day)
                        <th>{{ $day }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($gridData['hourRows'] as $rowIndex => $hourRow)
                    <tr>
                        <td class="grid-time-cell">{{ $hourRow['label'] }}</td>
                        @foreach($gridData['days'] as $day)
                            @if(isset($gridBlocksByCell[$day][$rowIndex]))
                                @php($block = $gridBlocksByCell[$day][$rowIndex])
                                <td class="grid-block-cell @if($block['online']) online @endif" rowspan="{{ $block['span'] }}">
                                    <p class="grid-block-subject">{{ $block['line1'] }}</p>
                                    <p class="grid-block-line">{{ $block['line2'] }}</p>
                                    <p class="grid-block-line">{{ $block['line3'] }}</p>
                                </td>
                            @elseif(! isset($gridCovered[$day][$rowIndex]))
                                <td class="grid-empty-cell"></td>
                            @endif
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($reportType === 'schedule_by_faculty' && !empty($report['facultyMeta']))
            @include('reports.partials.faculty-signoff', ['facultyName' => $report['facultyMeta']['full_name'], 'deans' => $report['facultyMeta']['deans'] ?? [], 'approvers' => $report['facultyMeta']['approvers'] ?? []])
        @endif

    @elseif($reportType === 'schedule_by_section' && !empty($report['groups']))

        {{-- Several specific (possibly non-contiguous — e.g. BSIT-1,
             BSIT-3, BSIT-4, skipping BSIT-2) sections picked at once:
             each gets its own heading + table + page break, instead of
             one continuous merged table, so this prints/saves as one
             document per section. --}}
        @foreach($report['groups'] as $index => $group)
            <div class="section-block" @if($index > 0) style="page-break-before: always;" @endif>
                @if($index > 0)
                    {{-- New physical page: repeat the full letterhead so this
                         section reads as a standalone document on its own. --}}
                    @include('reports.partials.letterhead')
                @endif

                <div class="section-header">
                    @if($group['program'])
                        <p class="program">{{ $group['program'] }}</p>
                    @endif
                    @if($group['academic_year'] || $group['semester'])
                        <p class="term">S.Y. {{ $group['academic_year'] }}{{ $group['academic_year'] && $group['semester'] ? ' · ' : '' }}{{ $group['semester'] }}</p>
                    @endif
                </div>

                <h2 class="section-heading">{{ $group['section_code'] }}</h2>

                @if(empty($group['rows']))
                    <p class="empty">No schedule found for this section.</p>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>EDP Code</th>
                                <th>Subject Code</th>
                                <th>Subject</th>
                                <th>Faculty</th>
                                <th>Room</th>
                                <th>Day / Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($group['rows'] as $row)
                                @include('reports.partials.section-schedule-row', ['row' => $row])
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endforeach

    @elseif($reportType === 'schedule_by_section')

        {{-- Purpose-built layout matching the school's paper schedule
             format: EDP Code / Subject Code / Subject / Faculty / Room /
             Day & Time — Day+Start+End are merged into one "Day/Time"
             column here since the Section is already named once in the
             header above rather than repeated per row. A split subject
             (Face-to-Face/Online) still prints as ONE row under its one
             EDP Code, with each Schedule line stacked in the Room and
             Day/Time cells — see section-schedule-row.blade.php. --}}
        <table>
            <thead>
                <tr>
                    <th>EDP Code</th>
                    <th>Subject Code</th>
                    <th>Subject</th>
                    <th>Faculty</th>
                    <th>Room</th>
                    <th>Day / Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    @include('reports.partials.section-schedule-row', ['row' => $row])
                @endforeach
            </tbody>
        </table>

    @elseif($reportType === 'schedule_by_faculty' && !empty($report['groups']))

        {{-- Multiple faculty picked at once: each gets its own heading +
             table + page break, same flow as Schedule by Section. --}}
        @foreach($report['groups'] as $index => $group)
            <div class="section-block" @if($index > 0) style="page-break-before: always;" @endif>
                @if($index > 0)
                    @include('reports.partials.letterhead')
                @endif

                <div class="section-header">
                    @if($group['academic_year'] || $group['semester'])
                        <p class="term">S.Y. {{ $group['academic_year'] }}{{ $group['academic_year'] && $group['semester'] ? ' · ' : '' }}{{ $group['semester'] }}</p>
                    @endif
                </div>

                <h2 class="section-heading">{{ $group['label'] }}</h2>

                @if(empty($group['rows']))
                    <p class="empty">No schedule found for this faculty member.</p>
                @else
                    <table>
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
                            @foreach($group['rows'] as $row)
                                @include('reports.partials.faculty-schedule-row', ['row' => $row])
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @include('reports.partials.faculty-signoff', ['facultyName' => $group['label'], 'deans' => $group['deans'] ?? [], 'approvers' => $group['approvers'] ?? []])
            </div>
        @endforeach

    @elseif($reportType === 'schedule_by_faculty' && !empty($report['facultyMeta']))

        {{-- Single, explicitly-picked faculty: the faculty's name is
             already named once above (here, in the section heading)
             so it is never repeated per row — same "one name, many
             subjects" layout as the Faculty Workload tab's Assigned
             Subjects table, instead of a generic column dump with a
             Faculty column repeating the same name on every line. --}}
        <h2 class="section-heading">{{ $report['facultyMeta']['full_name'] }}</h2>

        @if(empty($report['rows']))
            <p class="empty">No schedule found for this faculty member.</p>
        @else
            <table>
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
                    @foreach($report['rows'] as $row)
                        @include('reports.partials.faculty-schedule-row', ['row' => $row])
                    @endforeach
                </tbody>
            </table>
        @endif

        @include('reports.partials.faculty-signoff', ['facultyName' => $report['facultyMeta']['full_name'], 'deans' => $report['facultyMeta']['deans'] ?? [], 'approvers' => $report['facultyMeta']['approvers'] ?? []])

    @else

        {{-- Every other report type: generic column dump, still under
             the same branded letterhead/meta block above. --}}
        <table>
            <thead>
                <tr>
                    @foreach($report['columns'] as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        @foreach($report['columns'] as $column)
                            <td>{{ $row[$column] ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

    @endif

    <div class="footer">
        <div class="footer-left">
            <span>Classly — Scheduling System</span>
            @if($signerName && $signerRole)
                <span class="footer-registrar">{{ $signerRole }}: {{ $signerName }}</span>
            @endif
        </div>
        <span>Generated: {{ $generatedAt->format('F j, Y g:i A') }}</span>
    </div>

    <script>
        // Auto-open the print dialog once the page (and logo image) has
        // painted — this page exists ONLY to be printed/saved as PDF, so
        // there's no reason to make the person click a second button.
        window.addEventListener('load', () => window.print());
    </script>

</body>
</html>