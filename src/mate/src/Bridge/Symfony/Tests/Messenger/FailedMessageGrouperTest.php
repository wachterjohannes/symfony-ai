<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Messenger\FailedMessage;
use Symfony\AI\Mate\Bridge\Symfony\Messenger\FailedMessageGrouper;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class FailedMessageGrouperTest extends TestCase
{
    public function testKeepsStatusCodesApartAndBlanksIds()
    {
        $grouper = new FailedMessageGrouper();

        $this->assertNotSame($grouper->pattern('HTTP 500 returned by the ERP'), $grouper->pattern('HTTP 404 returned by the ERP'));
        $this->assertSame($grouper->pattern('Order 10231 not found'), $grouper->pattern('Order 99812 not found'));
        $this->assertSame($grouper->pattern('Price "12,50" is invalid'), $grouper->pattern('Price "9,63" is invalid'));
        $this->assertSame($grouper->pattern('Amount 12.50 exceeds the limit'), $grouper->pattern('Amount 9.63 exceeds the limit'));
    }

    public function testTellsApplicationFramesFromVendorFramesOnEveryPathStyle()
    {
        $groups = (new FailedMessageGrouper(['/home/ci/vendor/acme-shop']))->group([
            $this->message('1', '/home/ci/vendor/acme-shop/src/Handler.php', '#0 /home/ci/vendor/acme-shop/vendor/symfony/messenger/Worker.php(10): run()'),
            $this->message('2', 'C:\\app\\vendor\\symfony\\messenger\\Worker.php', '#0 C:\\app\\src\\Handler.php(20): App\\Handler->__invoke()'),
        ]);

        $this->assertSame(['src/Handler.php:12', 'C:\\app\\src\\Handler.php:20'], array_column($groups, 'failed_in'));
    }

    private function message(string $id, string $file, string $trace): FailedMessage
    {
        return new FailedMessage($id, 'App\\Message', \RuntimeException::class, 'Boom '.$id, $file, 12, $trace, 0, null, null, null);
    }
}
