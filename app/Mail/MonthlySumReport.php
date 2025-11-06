<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MonthlySumReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public array $result, public \DateTimeInterface $runAt, public float $durationMs)
    {
    }

    public function build(): self
    {
        $this->subject('Notion月次集計 '.$this->result['year_month'].' 完了');

        return $this->text('mail.monthly_sum_report', [
            'result' => $this->result,
            'runAt' => $this->runAt,
            'durationMs' => $this->durationMs,
        ]);
    }
}
