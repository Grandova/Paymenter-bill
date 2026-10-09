<?php

namespace Paymenter\Extensions\Servers\Clicd\Jobs;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncExpiry implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = 30;

    public function __construct(public Service $service) {}

    public function handle(): void
    {
        ExtensionHelper::callService($this->service, 'syncExpiry');
    }
}
