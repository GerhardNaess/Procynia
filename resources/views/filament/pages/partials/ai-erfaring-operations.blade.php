{{-- Per-operation statistics from AiCapacityCalibrationService::operationStatistics() — the same figures ai:capacity-analysis prints. --}}
<div class="exp-scroll"><table class="exp-table mt-2" data-testid="{{ $testid }}">
    <thead>
        <tr>
            <th>{{ $t('columns.operation') }}</th>
            <th class="num">{{ $t('columns.calls') }} · {{ $t('columns.share') }}</th>
            <th class="num">{{ $t('columns.cost') }} ({{ $t('columns.mean') }} · {{ $t('columns.median') }} · {{ $t('columns.p75') }} · {{ $t('columns.p95') }})</th>
            <th class="num">{{ $t('columns.mean_estimate') }} · {{ $t('columns.ratio') }}</th>
            <th class="num">{{ $t('columns.failure_rate') }} · {{ $t('columns.open_rate') }}</th>
            <th>{{ $t('columns.assessment') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td data-label="{{ $t('columns.operation') }}" class="break-all">{{ $row['operation_key'] }}</td>
                <td class="num" data-label="{{ $t('columns.calls') }}">{{ $num($row['calls']) }} · {{ $pct($row['share']) }}</td>
                <td class="num" data-label="{{ $t('columns.cost') }}">{{ $nok($row['mean_actual_nok']) }} · {{ $nok($row['median_actual_nok']) }} · {{ $nok($row['p75_actual_nok']) }} · {{ $nok($row['p95_actual_nok']) }}</td>
                <td class="num" data-label="{{ $t('columns.mean_estimate') }}">{{ $nok($row['mean_estimate_nok']) }} · {{ $row['estimate_actual_ratio'] === null ? $t('none') : $num($row['estimate_actual_ratio'], 2) }}</td>
                <td class="num" data-label="{{ $t('columns.failure_rate') }}">{{ $pct($row['failure_rate'] * 100) }} · {{ $pct($row['open_rate'] * 100) }}</td>
                <td data-label="{{ $t('columns.assessment') }}">{{ $row['assessment'] === null ? $t('none') : $t('assessments.'.$row['assessment']) }}</td>
            </tr>
        @empty
            <tr><td colspan="6">{{ $t('customers.empty') }}</td></tr>
        @endforelse
    </tbody>
</table></div>
