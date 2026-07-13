<?php

declare(strict_types=1);

namespace App\Services\Sermon;

class SermonAnalysisPromptBuilder
{
    public function __construct(private readonly SermonAnalysisValidator $validator) {}

    /**
     * Build comprehensive analysis prompt for OpenAI
     *
     * @param  string  $transcript  The sermon transcript
     * @param  array<int, string>  $existingSeries  Array of existing series names
     * @return string The formatted prompt
     */
    public function buildAnalysisPrompt(string $transcript, array $existingSeries): string
    {
        $seriesList = empty($existingSeries) ? 'None available' : implode(', ', $existingSeries);

        return <<<PROMPT
Analyze this Christian sermon transcript and extract the following information. Return your response as a JSON object with the specified structure.

EXISTING SERMON SERIES (match one if applicable):
{$seriesList}

TRANSCRIPT:
{$transcript}

Please provide a JSON response with this exact structure:
{
    "title": "A descriptive sermon title in sentence case (maximum 12 words and 60 characters)",
    "series": "Name of matching existing series or null if no match",
    "reference": "Primary Bible passage being preached (e.g., 'John 3:16-21')",
    "points": ["Main point 1 in sentence case", "Main point 2 in sentence case", "Main point 3 in sentence case"],
    "summary": "A concise summary of the sermon in under 100 words using British English"
}

ANALYSIS GUIDELINES:

1. TITLE: Create a clear, engaging title that captures the sermon's main theme. Rules:
   - Maximum 12 words
   - Maximum 60 characters
   - Focus on the central message or key Bible passage
   - Use language from the transcript where possible
   - Use sentence case (capitalise only the first word and proper nouns, not every word)

2. SERIES: Only match to an existing series if the content clearly belongs to that series. Look for:
   - Book studies (e.g., "1 John", "Romans", "Genesis")
   - Thematic series (e.g., "Christmas messages", "Easter", "Prayer")
   - If uncertain or no clear match, return null

3. REFERENCE: Identify the PRIMARY Bible passage being expounded. This should be:
   - The main text the preacher is working through
   - Not just verses quoted in passing
   - Format as "Book Chapter:Verse-Verse" (e.g., "Romans 8:28-39")
   - If no clear primary passage, return null

4. POINTS: Extract 2-7 main sermon points/headings that structure the message:
   - Focus on the preacher's main divisions or arguments
   - Use the preacher's own words where possible
   - Use sentence case - don't capitalise every word
   - If creating points yourself, use clear, concise British English, matching the preacher's tone of voice
   - Stick to below 12 words per point
   - If no clear structure is evident, create logical divisions based on content flow
   - Use sub-points if they help to structure the message, but don't overcomplicate it

5. SUMMARY: Create a concise summary of the sermon in under 100 words that:
   - Captures the main message and key themes
   - Stays faithful to the transcript content
   - Never introduces new information or ideas that are not in the transcript
   - Uses clear, accessible, persuasive British English as would be expected from a sermon in a British conservative evangelical church
   - Matches the tone of the sermon
   - Uses "we" and "us" rather than "Christians" or "believers"
   - Uses active language, not passive
   - Never mentions "sermon" or "message" in the summary. Instead, talk as if you're the preacher summarising their own sermon

Respond only with the JSON object, no additional text.
PROMPT;
    }

    /**
     * Generate a basic title from transcript when AI fails
     *
     * @param  string  $transcript  The sermon transcript
     * @return string Generated title
     */
    public function generateFallbackTitle(string $transcript): string
    {
        // Try to extract a meaningful phrase from the beginning
        $words = explode(' ', trim($transcript));

        // Skip common sermon openings
        $skipWords = ['good', 'morning', 'evening', 'welcome', 'today', 'we', 'are', 'going', 'to', 'look', 'at'];

        $meaningfulWords = collect($words)
            ->filter(function (string $word) use ($skipWords): bool {
                $cleanWord = strtolower(trim($word, '.,!?;:'));

                return ! in_array($cleanWord, $skipWords, true) && strlen($cleanWord) > 2;
            })
            ->take(4);

        if ($meaningfulWords->count() >= 2) {
            return $this->validator->validateAndCleanTitle($meaningfulWords->implode(' '));
        }

        // Final fallback
        return 'Sermon - '.date('F j, Y');
    }
}
