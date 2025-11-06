<?php

namespace App\Services\Notify;

use App\Mail\MonthlySumReport;
use Illuminate\Support\Facades\Mail;

class MailNotifier
{
    public function sendMonthlyReport(array $payload): void
    {
        $to = config('services.report.mail_to');
        if (! filled($to)) {
            return;
        }

        $mailable = new MonthlySumReport($payload['result'], $payload['run_at'], $payload['duration_ms']);

        Mail::to($to)->send($mailable);
    }
}
