Notion月次集計 {{ $result['year_month'] }} 完了
実行時刻: {{ $runAt->copy()->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s T') }}
期間: {{ $result['range']['start'] }} - {{ $result['range']['end'] }}
処理時間: {{ number_format($durationMs, 2) }} ms
@foreach($result['totals'] as $account => $amount)
{{ $account }}: {{ number_format((float) $amount) }}
@endforeach
@if(!empty($result['carry_over_status']))
繰越登録状況:
@php
    $carryOverSuccess = array_filter($result['carry_over_status'], fn ($entry) => ($entry['status'] ?? null) === 'success');
    $carryOverFailure = array_filter($result['carry_over_status'], fn ($entry) => ($entry['status'] ?? null) === 'failure');
@endphp
@if(empty($carryOverFailure))
・全件成功
@else
@if(!empty($carryOverSuccess))
・成功: {{ implode(', ', array_map(function ($entry) {
    $createdAt = $entry['created_at'] ?? null;

    return $createdAt ? sprintf('%s(作成日: %s)', $entry['account'], $createdAt) : $entry['account'];
}, $carryOverSuccess)) }}
@endif
・失敗: {{ implode(', ', array_map(fn ($entry) => $entry['account'], $carryOverFailure)) }}
@endif
@endif
