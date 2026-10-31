<?php

namespace App\Http\Controllers;

use App\Support\OwnerCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Read-only reference for the platform owner. Nothing is executed from the web. */
class OwnerCommandsController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize('platform');

        return view('platform.owner-commands', ['groups' => OwnerCommands::GROUPS, 'commands' => OwnerCommands::COMMANDS]);
    }
}
