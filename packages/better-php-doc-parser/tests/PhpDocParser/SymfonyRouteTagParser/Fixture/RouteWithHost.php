<?php

declare(strict_types=1);

namespace Rector\BetterPhpDocParser\Tests\PhpDocParser\SymfonyRouteTagParser\Fixture;

use Symfony\Component\Routing\Annotation\Route;

final class RouteWithHost
{
    /**
     * @Route("/user", name="user_index", host="%test%", methods={"GET"})
     */
    public function run()
    {
    }
}
