Notion月次集計 {{ $result['year_month'] }} 完了
実行時刻: {{ $runAt->copy()->setTimezone('UTC')->format('Y-m-d H:i:s \U\T\C') }}
期間: {{ $result['range']['start'] }} - {{ $result['range']['end'] }}
処理時間: {{ number_format($durationMs, 2) }} ms
件数: {{ $result['records_count'] }}
@foreach($result['totals'] as $account => $amount)
{{ $account }}: {{ number_format((float) $amount) }}
@endforeach
