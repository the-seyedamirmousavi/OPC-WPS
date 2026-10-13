<?php

namespace App\AI\Semantic;

/**
 * Ranks texts against a query. The lexical engine (TextSimilarity) always runs. When an embedding model is available the
 * meaning of the texts is blended in, so «پرسنل شرکت» finds the users intents and «جوشکاری» finds a welding operation
 * whose name is English. When it is not, the answer is the lexical one and nothing else changes. The thresholds differ
 * between the two modes because the scores are not on the same scale; ask `threshold()` after ranking.
 */
class SemanticRanker
{
    /** how much of the final score comes from the embedding when it is available */
    private const EMBEDDING_WEIGHT = 0.65;
    /** more candidates than this are first narrowed by the lexical score, to bound the embedding work */
    private const EMBED_ALL_UP_TO = 150;
    private const EMBED_TOP = 100;

    private bool $embedded = false;

    public function __construct(private readonly Embedder $embedder)
    {
    }

    /** Whether the last ranking used embeddings. */
    public function usedEmbeddings(): bool
    {
        return $this->embedded;
    }

    /** The mode the next ranking will use, for the trace. */
    public function mode(): string
    {
        return $this->embedder->mode();
    }

    /** The threshold of the mode the last ranking used. */
    public function threshold(string $name): float
    {
        $key = $this->embedded ? "aiso.agent.semantic_embedding.{$name}" : "aiso.agent.semantic.{$name}";

        return (float) config($key);
    }

    /**
     * @param array<int|string, string> $docs key => text
     * @return array<int, array{0: int|string, 1: float}> best first
     */
    public function rank(string $query, array $docs, int $top = 10): array
    {
        $this->embedded = false;
        $lexical = [];
        foreach (TextSimilarity::rank($query, $docs, count($docs)) as [$key, $score]) {
            $lexical[$key] = $score;
        }
        // the keys that did not match the query at all still need a score
        foreach ($docs as $key => $_) {
            $lexical[$key] ??= 0.0;
        }

        $scores = $lexical;
        if ($this->embedder->available() && $docs !== []) {
            $chosen = count($docs) <= self::EMBED_ALL_UP_TO
                ? array_keys($docs)
                : array_slice(array_keys($lexical), 0, self::EMBED_TOP);
            arsort($lexical);
            $vectors = $this->embedder->embed([$query, ...array_map(fn ($k) => $docs[$k], $chosen)]);
            if ($vectors !== null) {
                $this->embedded = true;
                $q = array_shift($vectors);
                $scores = [];
                foreach ($lexical as $key => $lex) {
                    $i = array_search($key, $chosen, true);
                    $emb = $i === false ? 0.0 : Embedder::cosine($q, $vectors[$i]);
                    $scores[$key] = (1 - self::EMBEDDING_WEIGHT) * $lex + self::EMBEDDING_WEIGHT * $emb;
                }
            }
        }

        arsort($scores);
        $out = [];
        foreach (array_slice($scores, 0, $top, true) as $key => $score) {
            $out[] = [$key, $score];
        }

        return $out;
    }
}
