<?php

namespace App\Contracts;

interface AccomplishmentAiService
{
    public function improve(string $text): string;
}
