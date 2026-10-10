<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_notification_does_not_generate_a_pdf_when_mail_is_disabled(): void
    {
        config()->set('settings.mail_disable', true);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id]);
        $invoice->number = 'pdf-skip-' . Str::uuid();
        $invoice->saveQuietly();
        $pdfPath = storage_path('app/invoices/' . $invoice->number . '.pdf');

        NotificationHelper::invoiceNotification($user, $invoice);

        $this->assertFileDoesNotExist($pdfPath);
    }
}
