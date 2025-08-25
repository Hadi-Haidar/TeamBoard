<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Get the frontend URL with optional path.
     */
    protected function getFrontendUrl(string $path = ''): string
    {
        return env('FRONTEND_URL') . $path;
    }
}
