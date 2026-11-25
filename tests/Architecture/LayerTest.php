<?php

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules, checked by PHPStan (phpat) in CI.
 *
 * They describe the layering the code already follows:
 * HTTP (controllers, middleware, requests) sits on top of services,
 * services sit on top of models/support/enums, and the lower layers
 * never reach back up.
 */
final class LayerTest
{
    public function test_models_do_not_depend_on_upper_layers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Models'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\Http'),
                Selector::inNamespace('App\Services'),
                Selector::inNamespace('App\Policies'),
                Selector::inNamespace('App\Console'),
            )
            ->because('models are the lowest layer and must not know about HTTP, services or policies');
    }

    public function test_services_do_not_depend_on_http(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Services'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('App\Http'))
            ->because('business logic must be usable from controllers, commands and tests alike');
    }

    public function test_support_is_framework_level_only(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Support'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\Http'),
                Selector::inNamespace('App\Services'),
                Selector::inNamespace('App\Policies'),
            )
            ->because('support helpers are shared building blocks below the service layer');
    }

    public function test_policies_do_not_depend_on_http_or_services(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Policies'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\Http'),
                Selector::inNamespace('App\Services'),
            )
            ->because('authorization decisions depend only on models');
    }

    public function test_enums_are_leaf_types(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Enums'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('App\Http'),
                Selector::inNamespace('App\Services'),
                Selector::inNamespace('App\Models'),
            )
            ->because('enums are plain value types');
    }

    public function test_application_code_does_not_depend_on_seeders_or_tests(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Database\Seeders'),
                Selector::inNamespace('Tests'),
            )
            ->because('seeders and tests are not deployed as application dependencies');
    }
}
