<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusLlmsTxt\Controller\LlmsTxtController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_llms_txt', '/llms.txt')
        ->controller([LlmsTxtController::class, 'index'])
        ->methods(['GET', 'HEAD']);

    $routes->add('koh_llms_full_txt', '/llms-full.txt')
        ->controller([LlmsTxtController::class, 'full'])
        ->methods(['GET', 'HEAD']);
};
