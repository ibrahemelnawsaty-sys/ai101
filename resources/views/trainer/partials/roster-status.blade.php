{{--
    One roster row's status pill. Drawn by the attendance page and by its live
    refresh, so a refreshed cell is the same markup as a reload (PRD §9.9.7).
    Variables: $entry  App\Presenters\Trainer\RosterEntry
    @see BR-08, BR-09 · PRD §9.9.7 · D-72
--}}
<x-ui.pill :variant="$entry->statusVariant" :icon="$entry->statusIcon">{{ $entry->statusLabel }}</x-ui.pill>
