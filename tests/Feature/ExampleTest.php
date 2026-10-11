<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/dashboard');

        $dashboard = $this->get('/dashboard');

        $dashboard->assertRedirect('/login');
    }
}
