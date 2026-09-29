<?php

namespace App\Contracts;

interface DtrParser
{
    /** @return array{employee_name:string,employee_id:?string,month:int,year:int,entries:array<int,array<string,mixed>>} */
    public function parse(string $filePath): array;
}
