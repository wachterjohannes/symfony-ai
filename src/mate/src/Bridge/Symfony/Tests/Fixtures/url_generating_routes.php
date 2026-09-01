<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Mirrors the shape Symfony's Router writes to var/cache/<env>/url_generating_routes.php.
 * Each route tuple: [variables, defaults, requirements, tokens, hostTokens, methods, schemes].
 */
return [
    'app_checkout' => [
        [],
        ['_controller' => 'App\\Controller\\CheckoutController::__invoke'],
        [],
        [['text', '/checkout']],
        [],
        ['POST'],
        [],
    ],
    'app_home' => [
        [],
        ['_controller' => 'App\\Controller\\HomeController::index'],
        [],
        [['text', '/']],
        [],
        ['GET'],
        [],
    ],
    'framework_template' => [
        [],
        ['_controller' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\TemplateController'],
        [],
        [['text', '/static']],
        [],
        [],
        [],
    ],
    'app_event_listener_route' => [
        [],
        ['_controller' => 'App\\EventListener\\RequestListener'],
        [],
        [['text', '/listener']],
        [],
        [],
        [],
    ],
    'app_user_post' => [
        ['id', 'post'],
        ['_controller' => 'App\\Controller\\UserPostController::show'],
        [],
        [
            ['variable', '/', '\\d+', 'post'],
            ['text', '/posts'],
            ['variable', '/', '\\d+', 'id'],
            ['text', '/users'],
        ],
        [],
        ['GET'],
        [],
    ],
];
