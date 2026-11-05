<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Reranker\TypeSafe;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\TypeSafe\Answer\Answers;
use Symfony\AI\Platform\Bridge\TypeSafe\Answer\NoulAnswer;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Reranker\RerankerInterface;
use Symfony\AI\Store\Reranker\TypeSafe\TypeSafeReranker;
use Symfony\AI\Store\Tests\Double\QueuedPlatformTestHandler;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class TypeSafeRerankerTest extends TestCase
{
    public function testItIsAReranker()
    {
        $this->assertInstanceOf(RerankerInterface::class, new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([])));
    }

    public function testItReturnsNothingForEmptyDocuments()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([], $handler));

        $evaluation = $reranker->evaluate('query', []);

        $this->assertSame([], $evaluation->getDocuments());
        $this->assertFalse($evaluation->isAnswerable());
        $this->assertSame([], $reranker->rerank('query', []));
        $this->assertSame([], $handler->payloads);
    }

    public function testItOrdersDocumentsByProbability()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.6, 'candidate_1' => 0.95, 'candidate_2' => 0.8], 0.9),
        ]));

        $result = $reranker->rerank('query', $this->documents(3));

        $this->assertSame(['doc-1', 'doc-2', 'doc-0'], $this->ids($result));
        $this->assertSame([0.95, 0.8, 0.6], array_map(static fn (VectorDocumentInterface $document): ?float => $document->getScore(), $result));
    }

    public function testItDropsDocumentsBelowTheThreshold()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.49, 'candidate_1' => 0.5, 'candidate_2' => 0.1], 0.9),
        ]));

        $this->assertSame(['doc-1'], $this->ids($reranker->rerank('query', $this->documents(3))));
    }

    public function testItUsesTheConfiguredThreshold()
    {
        $reranker = new TypeSafeReranker(
            QueuedPlatformTestHandler::createPlatform([$this->answers(['candidate_0' => 0.3, 'candidate_1' => 0.2], 0.9)]),
            threshold: 0.25,
        );

        $this->assertSame(['doc-0'], $this->ids($reranker->rerank('query', $this->documents(2))));
    }

    public function testItLimitsTheResultToTopK()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.7, 'candidate_1' => 0.9, 'candidate_2' => 0.8], 0.9),
        ]));

        $this->assertSame(['doc-1', 'doc-2'], $this->ids($reranker->rerank('query', $this->documents(3), 2)));
    }

    public function testItKeepsTheOrderOfRetrievalForEqualProbabilities()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.8, 'candidate_1' => 0.8, 'candidate_2' => 0.8], 0.9),
        ]));

        $this->assertSame(['doc-0', 'doc-1', 'doc-2'], $this->ids($reranker->rerank('query', $this->documents(3))));
    }

    public function testTheGatePasses()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.9], 0.7),
        ]));

        $evaluation = $reranker->evaluate('query', $this->documents(1));

        $this->assertSame(0.7, $evaluation->getAnswerability());
        $this->assertTrue($evaluation->isAnswerable());
    }

    public function testTheGateFails()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.9], 0.2),
        ]));

        $evaluation = $reranker->evaluate('query', $this->documents(1));

        $this->assertSame(0.2, $evaluation->getAnswerability());
        $this->assertFalse($evaluation->isAnswerable());
        $this->assertSame(['doc-0'], $this->ids($evaluation->getDocuments()));
    }

    public function testTheGateUsesItsOwnThreshold()
    {
        $reranker = new TypeSafeReranker(
            QueuedPlatformTestHandler::createPlatform([$this->answers(['candidate_0' => 0.9], 0.7)]),
            gateThreshold: 0.8,
        );

        $this->assertFalse($reranker->evaluate('query', $this->documents(1))->isAnswerable());
    }

    public function testItAsksOneEvaluationPerQuery()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.9, 'candidate_1' => 0.9], 0.9),
        ], $handler), 'jev-1.13.0');

        $reranker->rerank('What is Symfony?', $this->documents(2));

        $this->assertCount(1, $handler->payloads);
        $payload = $handler->payloads[0];
        $this->assertSame('What is Symfony?', $payload['state']);
        $this->assertSame(['candidate_0', 'candidate_1', 'gate'], array_keys($payload['questions']));
        $this->assertSame('noul', $payload['questions']['candidate_0']['type']);
        $this->assertStringContainsString('Text 0', $payload['questions']['candidate_0']['instructions']);
        $this->assertStringNotContainsString('Text 1', $payload['questions']['candidate_0']['instructions']);
        $this->assertStringContainsString('Text 0', $payload['questions']['gate']['instructions']);
        $this->assertStringContainsString('Text 1', $payload['questions']['gate']['instructions']);
    }

    public function testItSplitsCandidatesOverSeveralRequests()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.6, 'candidate_1' => 0.7], 0.4),
            $this->answers(['candidate_2' => 0.9, 'candidate_3' => 0.8], 0.85),
            $this->answers(['candidate_4' => 0.65], 0.1),
        ], $handler), maxQuestionsPerRequest: 2);

        $evaluation = $reranker->evaluate('query', $this->documents(5));

        $this->assertCount(3, $handler->payloads);
        $this->assertSame(['candidate_2', 'candidate_3', 'gate'], array_keys($handler->payloads[1]['questions']));
        $this->assertSame(['doc-2', 'doc-3', 'doc-1', 'doc-4', 'doc-0'], $this->ids($evaluation->getDocuments()));
        $this->assertSame(0.85, $evaluation->getAnswerability());
    }

    public function testItAcceptsDocumentsWithoutText()
    {
        $reranker = new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([
            $this->answers(['candidate_0' => 0.9], 0.9),
        ], $handler));

        $reranker->rerank('query', [new VectorDocument('doc-0', new Vector([0.1]), new Metadata([Metadata::KEY_SOURCE => 'file.md']))]);

        $this->assertStringContainsString('file.md', $handler->payloads[0]['questions']['candidate_0']['instructions']);
    }

    public function testItRejectsAnInvalidThreshold()
    {
        $this->expectException(InvalidArgumentException::class);

        new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([]), threshold: 1.5);
    }

    public function testItRejectsAnInvalidChunkSize()
    {
        $this->expectException(InvalidArgumentException::class);

        new TypeSafeReranker(QueuedPlatformTestHandler::createPlatform([]), maxQuestionsPerRequest: 0);
    }

    /**
     * @param array<string, float> $candidates
     */
    private function answers(array $candidates, float $gate): ObjectResult
    {
        $answers = array_map(static fn (float $probability): NoulAnswer => new NoulAnswer($probability), $candidates);
        $answers['gate'] = new NoulAnswer($gate);

        return new ObjectResult(new Answers($answers));
    }

    /**
     * @return list<VectorDocument>
     */
    private function documents(int $count): array
    {
        $documents = [];
        for ($i = 0; $i < $count; ++$i) {
            $documents[] = new VectorDocument('doc-'.$i, new Vector([0.1]), new Metadata([Metadata::KEY_TEXT => 'Text '.$i]));
        }

        return $documents;
    }

    /**
     * @param list<VectorDocumentInterface> $documents
     *
     * @return list<string>
     */
    private function ids(array $documents): array
    {
        return array_map(static fn (VectorDocumentInterface $document): string => (string) $document->getId(), $documents);
    }
}
