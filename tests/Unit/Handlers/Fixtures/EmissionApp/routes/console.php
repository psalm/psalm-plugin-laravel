<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Scheduling an event never registers a route, so this name must not hide route('scheduled_report').
Schedule::command('reports:run')->name('scheduled_report');
