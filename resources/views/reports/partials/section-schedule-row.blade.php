{{--
    One row of the Study Load / Schedule by Section print table. A
    class with more than one Schedule line (e.g. a Face-to-Face/Online
    split) still prints as ONE row under its one EDP Code — never
    split into separate rows repeating the same EDP Code/Subject — so
    this renders every line in $row['Schedules'] stacked inside the
    same Room / Day-Time cells.

    Expects:
      $row — array{EDP Code, Subject Code, Subject, Faculty,
                    Schedules: list<array{Day, Start, End, Room}>}
--}}
<tr>
    <td>{{ $row['EDP Code'] ?? '—' }}</td>
    <td>{{ $row['Subject Code'] ?? '—' }}</td>
    <td>{{ $row['Subject'] ?? '—' }}</td>
    <td>{{ $row['Faculty'] ?? '—' }}</td>
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
</tr>