<?php

namespace App\Console\Commands;

use App\Services\BritishEnglishConverter;
use Illuminate\Console\Command;

class TestBritishEnglishConverter extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sermon:test-british-english {text?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the British English converter with sample text';

    /**
     * Execute the console command.
     */
    public function handle(BritishEnglishConverter $converter): int
    {
        $text = $this->argument('text');

        if (! $text) {
            // Use sample text for testing
            $text = "Today we will analyze how God wants us to organize our lives. We need to realize that His favor and honor are what we should prioritize. The defense of our faith requires us to recognize that we must emphasize the importance of being baptized and evangelized. Let's look at the theater of God's love and how it colors our behavior.";

            $this->info('Using sample text for testing:');
            $this->line($text);
            $this->newLine();
        }

        $this->info('Converting to British English...');
        $converted = $converter->convert($text);

        $this->info('Result:');
        $this->line($converted);
        $this->newLine();

        // Show statistics
        $stats = $converter->getStats();
        $this->info('Converter Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total patterns', $stats['total_patterns']],
                ['External wordlist available', $stats['external_wordlist_available'] ? 'Yes' : 'No'],
                ['Cache TTL', $stats['cache_ttl'].' seconds'],
            ]
        );

        return Command::SUCCESS;
    }
}
