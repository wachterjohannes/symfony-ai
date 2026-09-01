<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Capability;

use HelgeSverre\Toon\DecodeOptions;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GraphResultTest extends TestCase
{
    public function testToArrayHasAllEnvelopeFields()
    {
        $result = new GraphResult(
            summary: 'A summary',
            primaryNodes: [['id' => 'service:foo', 'type' => 'service', 'label' => 'Foo']],
            findings: ['Has 1 dependency.'],
            evidence: [['kind' => 'edge', 'from' => 'service:foo', 'relation' => 'depends_on', 'to' => 'service:bar']],
            relatedNodes: [['id' => 'service:bar', 'type' => 'service']],
            nextActions: [['tool' => 'symfony-graph', 'args' => ['node' => 'service:foo']]],
        );

        $array = $result->toArray();

        $this->assertSame('A summary', $array['summary']);
        $this->assertCount(1, $array['primaryNodes']);
        $this->assertCount(1, $array['findings']);
        $this->assertCount(1, $array['evidence']);
        $this->assertCount(1, $array['relatedNodes']);
        $this->assertCount(1, $array['nextActions']);
        $this->assertSame([], $array['warnings']);
    }

    public function testEncodingRoundTrips()
    {
        $result = new GraphResult(
            summary: 'Roundtrip',
            primaryNodes: [['id' => 'service:foo', 'type' => 'service', 'label' => 'Foo']],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['warning one'],
        );

        $encoded = ResponseEncoder::encode($result->toArray());
        $decoded = Toon::decode($encoded, DecodeOptions::lenient());

        $this->assertSame('Roundtrip', $decoded['summary']);
        $this->assertSame(['warning one'], $decoded['warnings']);
    }

    public function testEncodingPreservesNestedTagMetadata()
    {
        $result = new GraphResult(
            summary: 'Tags',
            primaryNodes: [[
                'id' => 'service:foo',
                'type' => 'service',
                'label' => 'Foo',
                'metadata' => [
                    'tagMetadata' => [
                        ['name' => 'kernel.event_listener', 'attributes' => ['event' => 'kernel.request', 'method' => 'onRequest']],
                        ['name' => 'doctrine.event_listener', 'attributes' => ['event' => 'postPersist']],
                    ],
                ],
            ]],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
        );

        $encoded = ResponseEncoder::encode($result->toArray());
        $decoded = Toon::decode($encoded, DecodeOptions::lenient());

        $this->assertSame(
            'kernel.event_listener',
            $decoded['primaryNodes'][0]['metadata']['tagMetadata'][0]['name'],
        );
        $this->assertSame(
            'kernel.request',
            $decoded['primaryNodes'][0]['metadata']['tagMetadata'][0]['attributes']['event'],
        );
    }
}
