<?php

namespace Tests\Unit\Services\EnterpriseWiki;

use App\Services\EnterpriseWiki\EnterpriseWikiClaimAnchorTextNormalizer;
use App\Services\EnterpriseWiki\EnterpriseWikiClaimCanonicalizationService;
use App\Services\EnterpriseWiki\EnterpriseWikiLinkParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plan §18, P0-2: the sentence-level normative check. Run 962 generated "skal" where the source
 * said "bør"/"bør aldri"; the old two-tier, whole-text modality check flagged some of these only
 * as a diagnostic and missed others. The fixtures are the 17 real claims of run 962 with the
 * source elements their blocks were generated from (tests/Fixtures/EnterpriseWiki/run962).
 */
class EnterpriseWikiNormativeStrengtheningTest extends TestCase
{
    private function service(): EnterpriseWikiClaimCanonicalizationService
    {
        return new EnterpriseWikiClaimCanonicalizationService(new EnterpriseWikiClaimAnchorTextNormalizer(new EnterpriseWikiLinkParser));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function run962Claims(): array
    {
        $claims = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/EnterpriseWiki/run962/claims.json'), true);

        return array_column($claims, null, 'claim_id');
    }

    /**
     * @param  array<string, mixed>  $claim
     * @return list<array{text: string, type: ?string}>
     */
    private function excerpts(array $claim): array
    {
        return array_map(
            static fn (array $element): array => ['text' => (string) $element['source_excerpt'], 'type' => $element['source_element_type']],
            $claim['source_elements'],
        );
    }

    #[DataProvider('modalityClasses')]
    public function test_modality_class(string $text, string $expected): void
    {
        $this->assertSame($expected, $this->service()->modalityClass($text));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function modalityClasses(): array
    {
        return [
            'skal' => ['Hvert tiltak skal ha én navngitt eier.', 'obligation'],
            'må' => ['Rådata må sikres før lagringstiden løper ut.', 'obligation'],
            'kreves' => ['Det kreves samtykke fra kunden.', 'obligation'],
            'shall' => ['The supplier shall document the review.', 'obligation'],
            'skal ikke' => ['Gapene skal ikke slås sammen.', 'prohibition'],
            'subordinate prohibition' => ['Gapene, som ikke skal slås sammen.', 'prohibition'],
            'må ikke' => ['Rapporten må ikke inneholde personnavn.', 'prohibition'],
            'must not' => ['Reports must not contain names.', 'prohibition'],
            'bør' => ['Forbedringssløyfen bør måles som en egen prosess.', 'recommendation'],
            'anbefales' => ['Følgende kriterier anbefales som utløsende:', 'recommendation'],
            'recommended' => ['A retrospective hunt is recommended.', 'recommendation'],
            'anbefales over skal' => ['Det anbefales at hvert tiltak skal ha en eier.', 'recommendation'],
            'bør aldri' => ['De bør derfor aldri slås sammen til ett tall.', 'negative_recommendation'],
            'bør ikke' => ['Møtet bør ikke ledes av Incident Commander.', 'negative_recommendation'],
            'frarådes' => ['Sammenslåing frarådes.', 'negative_recommendation'],
            'kan' => ['Kunden kan velge en forenklet vurdering.', 'permission'],
            'may' => ['The customer may choose a simplified review.', 'permission'],
            'skal kunne' => ['Tiltaket skal kunne verifiseres.', 'obligation'],
            'descriptive' => ['Gjennomgangen bygger på en etterprøvbar tidslinje.', 'descriptive'],
            'should is not a recommendation marker' => ['The response time should be measured from registration.', 'descriptive'],
        ];
    }

    public function test_run_962_recommendation_turned_into_requirement_is_detected(): void
    {
        $claims = $this->run962Claims();

        foreach ([438, 439, 440, 442] as $claimId) {
            $finding = $this->service()->detectNormativeStrengthening($claims[$claimId]['claim_text'], $this->excerpts($claims[$claimId]));

            $this->assertNotNull($finding, "claim {$claimId}");
            $this->assertSame(EnterpriseWikiClaimCanonicalizationService::NORMATIVE_STATUS_STRENGTHENED, $finding['status'], "claim {$claimId}");
        }

        $finding = $this->service()->detectNormativeStrengthening($claims[440]['claim_text'], $this->excerpts($claims[440]));
        $this->assertSame('recommendation', $finding['source_class']);
        $this->assertSame('obligation', $finding['claim_class']);
        $this->assertStringContainsString('bør måles', $finding['source_sentence']);

        $finding = $this->service()->detectNormativeStrengthening($claims[438]['claim_text'], $this->excerpts($claims[438]));
        $this->assertSame('negative_recommendation', $finding['source_class']);
        $this->assertSame('prohibition', $finding['claim_class']);
    }

    public function test_run_962_faithful_claims_are_not_flagged(): void
    {
        $claims = $this->run962Claims();

        // 427–436 were faithful (434 keeps "bør derfor ikke"); 437/443 restate a recommendation as
        // description and 431 restates a description as "skal" — neither is the escalating pair
        // and both stay with the existing diagnostic handling. 428/441 were the negation/subject
        // false positives and contain no strengthening.
        foreach ([427, 428, 429, 430, 431, 432, 433, 434, 435, 436, 437, 441, 443] as $claimId) {
            $this->assertNull(
                $this->service()->detectNormativeStrengthening($claims[$claimId]['claim_text'], $this->excerpts($claims[$claimId])),
                "claim {$claimId}",
            );
        }
    }

    #[DataProvider('validParaphrases')]
    public function test_valid_paraphrases_are_not_flagged(string $source, string $claim): void
    {
        $this->assertNull($this->service()->detectNormativeStrengthening($claim, [['text' => $source, 'type' => 'paragraph']]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validParaphrases(): array
    {
        return [
            'anbefales → bør' => [
                'Det anbefales at forbedringssløyfen måles som en egen prosess.',
                'Forbedringssløyfen bør måles som en egen prosess.',
            ],
            'må → skal' => [
                'Rådata må sikres før ordinær lagringstid løper ut.',
                'Rådata skal sikres før ordinær lagringstid løper ut.',
            ],
            'bør aldri → bør ikke' => [
                'Deteksjonsgap og responsgap bør aldri slås sammen til ett tall.',
                'Deteksjonsgap og responsgap bør ikke slås sammen til ett tall.',
            ],
            'kreves → skal' => [
                'Deling av læringspunkter krever at kunden samtykker og at materialet merkes.',
                'Deling av læringspunkter skal skje med kundens samtykke og merkes.',
            ],
            'english recommended → norwegian bør' => [
                'A retrospective threat hunt across the monitored environment is recommended.',
                'En retrospektiv trusseljakt i hele det overvåkede miljøet bør gjennomføres.',
            ],
            'unrelated bør elsewhere does not flag a faithful skal' => [
                'Hvert tiltak skal ha én navngitt eier og en frist. Møtet bør fasiliteres av en uavhengig person.',
                'Hvert tiltak skal ha én navngitt eier og en frist.',
            ],
        ];
    }

    #[DataProvider('strengthenings')]
    public function test_strengthening_is_detected(string $source, string $claim, string $sourceClass, string $claimClass): void
    {
        $finding = $this->service()->detectNormativeStrengthening($claim, [['text' => $source, 'type' => 'paragraph']]);

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::NORMATIVE_STATUS_STRENGTHENED, $finding['status']);
        $this->assertSame($sourceClass, $finding['source_class']);
        $this->assertSame($claimClass, $finding['claim_class']);
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function strengthenings(): array
    {
        return [
            'bør → skal' => [
                'Selve møtet bør fasiliteres av en person uten ledende rolle under hendelsen.',
                'Selve møtet skal fasiliteres av en person uten ledende rolle under hendelsen.',
                'recommendation', 'obligation',
            ],
            'bør ikke → skal ikke' => [
                'Gjennomgangen bør ikke bli en vurdering av enkeltpersoners innsats.',
                'Gjennomgangen skal ikke bli en vurdering av enkeltpersoners innsats.',
                'negative_recommendation', 'prohibition',
            ],
            'kan → må' => [
                'Kunden kan velge en forenklet vurdering for mindre hendelser.',
                'Kunden må velge en forenklet vurdering for mindre hendelser.',
                'permission', 'obligation',
            ],
            'recommended → must' => [
                'A retrospective threat hunt across the monitored environment is recommended.',
                'A retrospective threat hunt across the monitored environment must be performed.',
                'recommendation', 'obligation',
            ],
            'a bør elsewhere in the claim no longer hides the skal' => [
                'Selve møtet bør fasiliteres av en person uten ledende rolle under hendelsen.',
                'Møtet skal fasiliteres av en person uten ledende rolle under hendelsen. Trusseletterretning bør delta ved kjent aktør.',
                'recommendation', 'obligation',
            ],
        ];
    }

    public function test_list_item_inherits_the_recommendation_of_its_lead_in(): void
    {
        $finding = $this->service()->detectNormativeStrengthening(
            'Full gjennomgang skal gjennomføres når samme rotårsak har gitt hendelser tidligere.',
            [
                ['text' => 'Ikke alle hendelser krever en full gjennomgang. Følgende kriterier anbefales som utløsende:', 'type' => 'paragraph'],
                ['text' => 'Avtalte tidsmål for kvittering, varsling eller eskalering ble brutt.', 'type' => 'list_item'],
                ['text' => 'Samme rotårsak har gitt hendelser tidligere.', 'type' => 'list_item'],
            ],
        );

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::NORMATIVE_STATUS_STRENGTHENED, $finding['status']);
        $this->assertSame('recommendation', $finding['source_class']);
    }

    public function test_tie_with_a_faithful_source_sentence_is_ambiguous_not_a_finding(): void
    {
        $finding = $this->service()->detectNormativeStrengthening(
            'Tiltaket skal følges opp i saken.',
            [
                ['text' => 'Tiltaket bør følges opp i saken.', 'type' => 'paragraph'],
                ['text' => 'Tiltaket skal følges opp i saken av eieren.', 'type' => 'paragraph'],
            ],
        );

        $this->assertNotNull($finding);
        $this->assertSame(EnterpriseWikiClaimCanonicalizationService::NORMATIVE_STATUS_AMBIGUOUS, $finding['status']);
    }

    public function test_canonical_fact_reuse_requires_the_same_normative_class(): void
    {
        $service = $this->service();

        $this->assertFalse($service->areEquivalentTexts(
            'Forbedringssløyfen bør måles som en egen prosess, ikke bare gjennom responstidene.',
            'Forbedringssløyfen skal måles som en egen prosess, ikke bare gjennom responstidene.',
        ));
        $this->assertFalse($service->areEquivalentTexts(
            'De to gapene bør aldri slås sammen til ett tall.',
            'De to gapene skal aldri slås sammen til ett tall.',
        ));
        $this->assertTrue($service->areEquivalentTexts(
            'Forbedringssløyfen bør måles som en egen prosess, ikke bare gjennom responstidene.',
            'Forbedringssløyfen bør derfor måles som en egen prosess, ikke bare gjennom de operative responstidene.',
        ));
    }

    public function test_weak_alignment_never_concludes_strengthening(): void
    {
        $this->assertNull($this->service()->detectNormativeStrengthening(
            'Tiltak skal registreres i sakssystemet med egen referanse.',
            [['text' => 'Funksjonen for trusseletterretning bør delta når hendelsen kan knyttes til en kjent aktør.', 'type' => 'paragraph']],
        ));
    }
}
