<?php

namespace App\Contracts;

interface ReportPdfRenderer
{
    public function render(string $html, string $orientation): string;
}
