<?php

namespace App\Services\Guardian\Contracts;

use App\Services\Guardian\CheckResult;

interface GuardianCheck
{
    /** The check's key in the health document (the guardian's runbooks map known keys). */
    public function key(): string;

    public function run(): CheckResult;
}
