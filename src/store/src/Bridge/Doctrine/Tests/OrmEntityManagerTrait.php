<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Author;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\AuthorProfile;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\Book;
use Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures\BookTranslation;

/**
 * Creates a fully functional in-memory sqlite EntityManager for round-trip tests.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
trait OrmEntityManagerTrait
{
    private function createOrmEntityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/Fixtures'], true);
        // method_exists() keeps compatibility with doctrine/orm versions predating native lazy objects
        // @phpstan-ignore function.alreadyNarrowedType
        if (\PHP_VERSION_ID >= 80400 && method_exists($config, 'enableNativeLazyObjects')) {
            $config->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);

        (new SchemaTool($entityManager))->createSchema([
            $entityManager->getClassMetadata(Book::class),
            $entityManager->getClassMetadata(Author::class),
            $entityManager->getClassMetadata(AuthorProfile::class),
            $entityManager->getClassMetadata(BookTranslation::class),
        ]);

        return $entityManager;
    }
}
