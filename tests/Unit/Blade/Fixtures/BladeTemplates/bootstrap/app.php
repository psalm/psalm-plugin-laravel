<?php

declare(strict_types=1);

use Fx\Demo\Providers\DemoServiceProvider;
use Fx\Demo\Rank;
use Fx\HasLabel;
use Fx\Level;
use Fx\Providers\ThrowingDirectiveProvider;
use Fx\Suit;
use Fx\Tag;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;

// Nothing autoloads `app/Providers` or `packages/`, which is the point for the Demo provider: it is
// reachable only because this file requires it, as a package-style monorepo's root bootstrap does.
// The directive provider's boot() runs as real PHP during the app boot below.
require_once __DIR__ . '/../app/Providers/ThrowingDirectiveProvider.php';
require_once __DIR__ . '/../packages/Demo/src/Providers/DemoServiceProvider.php';
require_once __DIR__ . '/../packages/Demo/src/Rank.php';
require_once __DIR__ . '/../app/Suit.php';
require_once __DIR__ . '/../app/AliasTargets.php';

// Classes registered as aliases (an enum, an interface, an attribute, a plain class, and an alias of an alias),
// the way an app lets templates call them without a `use` statement.
// RegisterFacades merges the config aliases into this loader instead of replacing it.
AliasLoader::getInstance()->alias('Suit', Suit::class);
AliasLoader::getInstance()->alias('SuitAgain', 'Suit');
AliasLoader::getInstance()->alias('Rank', Rank::class);
AliasLoader::getInstance()->alias('IfaceAlias', HasLabel::class);
AliasLoader::getInstance()->alias('AttrAlias', Tag::class);
AliasLoader::getInstance()->alias('ClsAlias', Level::class);

return Application::configure(basePath: \dirname(__DIR__))
    ->withProviders([ThrowingDirectiveProvider::class, DemoServiceProvider::class])
    ->create();
