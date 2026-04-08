<?php

namespace Tests\Unit\Support;

use App\Support\CosineSimilarity;
use Tests\TestCase;

class CosineSimilarityTest extends TestCase
{
    public function test_it_returns_expected_similarity_for_valid_vectors(): void
    {
        $similarity = app(CosineSimilarity::class)->calculate([1.0, 0.0], [1.0, 1.0]);

        $this->assertNotNull($similarity);
        $this->assertEqualsWithDelta(0.70710678, $similarity, 0.00001);
    }

    public function test_it_returns_null_for_mismatched_dimensions(): void
    {
        $similarity = app(CosineSimilarity::class)->calculate([1.0, 0.0], [1.0, 0.0, 0.0]);

        $this->assertNull($similarity);
    }

    public function test_it_returns_null_for_zero_vectors(): void
    {
        $similarity = app(CosineSimilarity::class)->calculate([0.0, 0.0], [1.0, 1.0]);

        $this->assertNull($similarity);
    }

    public function test_it_returns_null_for_invalid_numeric_content(): void
    {
        $similarity = app(CosineSimilarity::class)->calculate([1.0, 'not-a-number'], [1.0, 1.0]);

        $this->assertNull($similarity);
    }
}
