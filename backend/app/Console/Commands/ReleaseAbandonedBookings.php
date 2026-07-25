<?php

namespace App\Console\Commands;

use App\Services\AbandonedBookingReleaser;
use Illuminate\Console\Command;

class ReleaseAbandonedBookings extends Command
{
    protected $signature = 'bookings:release-abandoned';

    protected $description = 'Cancel bookings whose online deposit was never completed, freeing the held slot';

    public function handle(AbandonedBookingReleaser $releaser): int
    {
        $released = $releaser->release();

        $this->info("Released {$released} abandoned booking(s).");

        return self::SUCCESS;
    }
}
