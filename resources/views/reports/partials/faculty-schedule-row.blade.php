{{--
    One row of the Schedule by Faculty print/email table. A class with
    more than one Schedule line (e.g. a Face-to-Face/Online split) still
    prints as ONE row under its one EDP Code — never split into
    separate rows with the Load counted twice — so this renders every
    line in $row['Schedules'] stacked inside the same Schedule/Room
    cells, matching the Workload tab's Assigned Subjects table.

    Expects:
      $row — array{EDP Code, Subject Code, Subject, Section, Units,
                    Schedules: list<array{Day, Start, End, Room}>}
--}}
<tr>
    <td>{{ $row['EDP Code'] ?? '—' }}</td>
    <td>
        <div class="subject-code">{{ $row['Subject Code'] ?? '—' }}</div>
        <div class="subject-title">{{ $row['Subject'] ?? '—' }}</div>
    </td>
    <td>{{ $row['Section'] ?? '—' }}</td>
    <td>
        @if(!empty($row['Schedules']))
            <div class="schedule-lines">
                @foreach($row['Schedules'] as $schedule)
                    <div>
                        @if(!empty($schedule['Day']) && !empty($schedule['Start']) && !empty($schedule['End']))
                            {{ $schedule['Day'] }} · {{ $schedule['Start'] }}–{{ $schedule['End'] }}
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
        @if(!empty($row['Schedules']))
            <div class="schedule-lines">
                @foreach($row['Schedules'] as $schedule)
                    <div>{{ $schedule['Room'] ?? '—' }}</div>
                @endforeach
            </div>
        @else
            —
        @endif
    </td>
    <td>{{ $row['Units'] ?? '—' }} Units</td>
</tr>