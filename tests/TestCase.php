<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Interface language of this test. The existing tests check the Dutch
     * texts, so they run in Dutch: guests get it as the application
     * default, users created in the test without an explicit locale get it
     * as their preference, and requests send it as the browser language.
     * Tests of the language behaviour set 'en' (the production default).
     */
    protected string $locale = 'nl';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => $this->locale]);
        app()->setLocale($this->locale);

        // Test requests send "Accept-Language: en-us" by default; public
        // status pages follow the browser language, so send the test language.
        $this->withHeader('Accept-Language', $this->locale);

        $locale = $this->locale;
        User::creating(function (User $user) use ($locale) {
            if ($user->getAttribute('locale') === null) {
                $user->setAttribute('locale', $locale);
            }
        });
    }
}
