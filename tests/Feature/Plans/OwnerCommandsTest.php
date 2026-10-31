<?php

namespace Tests\Feature\Plans;

use App\Models\User;
use App\Support\OwnerCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OwnerCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_administrators_see_the_page(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('platform.commands'))->assertForbidden();

        $platform = User::factory()->create();
        $platform->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($platform)->get(route('platform.commands'))->assertOk()
            ->assertSee('php artisan migrate --force')->assertSee('serverscope:prune --dry-run');
    }

    public function test_every_serverscope_command_is_listed_and_every_listed_one_exists(): void
    {
        $registered = collect(Artisan::all())->keys()->filter(fn ($n) => str_starts_with($n, 'serverscope:'))->values()->all();
        $listed = OwnerCommands::artisanNames();

        $this->assertSame([], array_values(array_diff($registered, $listed)), 'Add new serverscope:* commands to App\Support\OwnerCommands.');
        foreach ($listed as $name) {
            if (str_starts_with($name, 'serverscope:')) {
                $this->assertContains($name, $registered);
            }
        }
    }

    public function test_every_entry_has_texts_in_both_languages_and_is_in_a_group(): void
    {
        $grouped = collect(OwnerCommands::GROUPS)->flatten()->all();
        $this->assertEqualsCanonicalizing(array_keys(OwnerCommands::COMMANDS), $grouped);

        foreach (['en', 'nl'] as $locale) {
            foreach ($grouped as $id) {
                $this->assertNotSame("owner.cmd.{$id}.title", trans("owner.cmd.{$id}.title", [], $locale));
                $this->assertNotSame("owner.cmd.{$id}.when", trans("owner.cmd.{$id}.when", [], $locale));
            }
            foreach (array_keys(OwnerCommands::GROUPS) as $g) {
                $this->assertNotSame("owner.groups.{$g}", trans("owner.groups.{$g}", [], $locale));
            }
        }
    }
}
