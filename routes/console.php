<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('pdfs:delete')->everySixHours();
