<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Support\CanonicalJson;
use RuntimeException;

/**
 * A content hash of every file that can change what a parser arm extracts.
 *
 * The evaluation plan asks the run manifest for a "prompt/schema hash" and a "parser-surface
 * commit". This does both jobs at once and does them better, for two reasons:
 *
 * - **A commit id does not prove the code.** It says nothing about uncommitted edits, which is
 *   precisely the risk while two arms are being set up hours apart. Hashing the bytes on disk
 *   catches a prompt tweaked between arm A and arm B whether or not anybody committed it.
 * - **The prompt and the schema are private to the extractor**, and prising them out through a new
 *   public method would put an evaluation-shaped hole in a production class. The file that contains
 *   them is a strict superset: any change to the prompt or the strict JSON schema changes it.
 *
 * The superset is the trade. A comment-only edit to one of these files also moves the hash and the
 * comparison refuses the pair — a false alarm, but one that fails safe and is cleared by rerunning
 * an arm rather than by trusting a stale artifact.
 *
 * Per-file hashes are recorded as well as the combined one so a drift refusal can name the file that
 * moved instead of just asserting that something did.
 *
 * This one-shot surface is deleted once the Luna adoption report is accepted and no rerun remains,
 * or at historic-import IC8 closeout at the latest.
 */
class OosParserSurfaceFingerprint
{
    /**
     * Everything between the email and the projection's extraction signature and routing category.
     *
     * The list is recorded in the manifest alongside the hashes, and the comparison requires both
     * arms to declare the *same list*: a file quietly dropped from here would otherwise stop being
     * drift-checked without anything saying so.
     */
    private const Files = [
        // The strict JSON schema, and the retry and adjudication calls.
        'app/Services/Email/OpenAiOosEmailItemExtractor.php',
        // Every system prompt variant. Which one an arm selected is a declared intervention and is
        // recorded in the run manifest; an *edit* to either text is not, and is caught here.
        'app/Services/Email/OosEmailExtractionPrompt.php',
        'app/Services/Email/OosSemanticAnnotationPrompt.php',
        'app/Services/Email/OpenAiOosSemanticAnnotator.php',
        'app/Services/Email/OpenAiOosSemanticRepairer.php',
        'app/Services/Email/OosSemanticAnnotationSchema.php',
        'app/Services/Email/OosSemanticAnnotationDecoder.php',
        'app/Services/Email/OosSemanticAnnotationValidator.php',
        // What the schema permits and the validator accepts for a continuation target, shared so the
        // two cannot drift apart.
        'app/Services/Email/OosSemanticContinuationRule.php',
        'app/Services/Email/ApplyOosSemanticAnnotationPatch.php',
        'app/Services/Email/CompileOosSemanticAnnotations.php',
        'app/Services/Email/OosServiceDateResolver.php',
        'app/Services/Email/OosSemanticParserCandidate.php',
        // Item normalisation, disposition, hold reasons, content scope, date plausibility.
        'app/Services/Email/OosEmailParserService.php',
        // The strict source-line and phantom-line rules the safety guardrail rests on.
        'app/Services/Email/OosEmailExtractionValidator.php',
        // Line numbering and the verbatim text an item's source title is taken from.
        'app/Data/OosEmailSourceDocument.php',
        'app/Data/OosCandidateService.php',
        'app/Data/OosSemanticLineAnnotation.php',
        'app/Data/OosSemanticAnnotationResult.php',
        'app/Data/OosSemanticAnnotationPatch.php',
        'app/Enums/OosSemanticRole.php',
        'app/Enums/OosSemanticItemKind.php',
        'app/Enums/OosSemanticUncertainty.php',
        // `isAutoImportable()` and friends, which decide the routing category.
        'app/Data/OosEmailServicePlan.php',
        // Effective reasoning effort and the completion ceiling actually sent, and the schema-size
        // guard that can refuse the request before it is sent at all.
        'app/Support/OpenAiChatPayload.php',
        'app/Support/OpenAiJsonSchemaLimits.php',
        // Display titles, which are part of the extraction signature.
        'app/Services/ChurchService/ServiceItemTitleCleaner.php',
    ];

    /**
     * @return array{hash:string,files:array<string,string>}
     */
    public function fingerprint(?string $basePath = null): array
    {
        $basePath ??= base_path();
        $files = [];

        foreach (self::Files as $file) {
            $path = "{$basePath}/{$file}";
            $contents = is_file($path) ? @file_get_contents($path) : false;

            if ($contents === false) {
                throw new RuntimeException("Refusing OoS parser evaluation: the parser surface file {$file} could not be read, so the arm cannot prove what code it ran.");
            }

            $files[$file] = hash('sha256', $contents);
        }

        return ['hash' => CanonicalJson::hash($files), 'files' => $files];
    }
}
