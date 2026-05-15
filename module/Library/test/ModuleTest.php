<?php

declare(strict_types=1);

namespace LibraryTest;

use Library\Controller\HomeController;
use Library\Controller\UserController;
use Library\Factory\Controller\HomeControllerFactory;
use Library\Factory\Form\BorrowFormFactory;
use Library\Module;
use Library\Factory\Controller\UserControllerFactory;
use Library\Form\BorrowForm;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Library\Module
 */
class ModuleTest extends TestCase
{
    public function testProvidesConfig(): void
    {
        $module = new Module();
        $config = $module->getConfig();

        self::assertArrayHasKey('router', $config);
        self::assertArrayHasKey('controllers', $config);
        self::assertArrayHasKey('service_manager', $config);
        self::assertArrayHasKey('form_elements', $config);
        self::assertArrayHasKey('view_manager', $config);

        self::assertSame(
            UserControllerFactory::class,
            $config['controllers']['factories'][UserController::class] ?? null
        );
        self::assertSame(
            HomeControllerFactory::class,
            $config['controllers']['factories'][HomeController::class] ?? null
        );

        self::assertArrayHasKey('library', $config['router']['routes']);
        self::assertArrayHasKey(
            'user',
            $config['router']['routes']['library']['child_routes'] ?? []
        );

        self::assertSame(
            BorrowFormFactory::class,
            $config['form_elements']['factories'][BorrowForm::class] ?? null
        );
    }
}
