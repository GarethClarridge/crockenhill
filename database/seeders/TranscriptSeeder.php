<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class TranscriptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->createTranscriptDirectory();
        $this->createMockSermonTranscript();
    }

    /**
     * Create the transcripts directory if it doesn't exist
     */
    private function createTranscriptDirectory(): void
    {
        if (!Storage::exists('transcripts')) {
            Storage::makeDirectory('transcripts');
            $this->command->info('Created transcripts directory');
        }
    }

    /**
     * Create the mock sermon transcript file for testing
     */
    private function createMockSermonTranscript(): void
    {
        $transcriptPath = 'transcripts/sermon_7.md';
        
        $transcriptContent = <<<'TRANSCRIPT'
Good morning, everyone. Today we're going to be looking at Romans chapter 8, verse 28. This is a passage that many of us know well, but I want us to examine it afresh this morning and see what God has to teach us through his word.

The apostle Paul writes, "And we know that in all things God works for the good of those who love him, who have been called according to his purpose."

This morning I want us to explore three main points about God's sovereign providence in our lives. First, we'll see that God is working in all circumstances. Second, that this work is always for our ultimate good. And third, that this promise is specifically for those who love God and are called according to his purpose.

Let's begin by considering what Paul means when he says "all things." The Greek word here is "panta" which means literally everything, without exception. Paul is not simply talking about the good times, the happy moments, or the successful seasons of our lives. He's talking about every single circumstance, including the difficult ones, the painful ones, the confusing ones.

You see, God's sovereignty extends over every aspect of our existence. There is nothing that happens in our lives that catches God by surprise. Nothing that falls outside of his ultimate control and purpose. This doesn't mean that God causes all things, but it does mean that he works through all things.

The second point I want us to consider is that God works all things together for good. Not that all things are good in themselves - clearly they are not. Sin, suffering, and death are not good things. But God, in his infinite wisdom and power, is able to weave even these difficult circumstances into his perfect plan for our lives.

Think of Joseph in the Old Testament. His brothers' jealousy and cruelty were not good things. Being sold into slavery was not a good thing. Being falsely accused and imprisoned was not a good thing. But God worked all of these circumstances together to position Joseph to save not only Egypt but also his own family from famine.

The third and final point is that this promise is not universal. It's not for everyone. Paul is very specific - it's for those who love God and are called according to his purpose. This is not positive thinking or wishful optimism. This is a rock-solid promise from almighty God to his children.

If you are a Christian this morning, if you have placed your trust in Jesus Christ for salvation, then you can rest assured that whatever circumstances you're facing right now, God is working them together for your ultimate good and his ultimate glory.

We see this most clearly demonstrated in the cross of Jesus Christ. The crucifixion was the most evil act in human history - the murder of the innocent Son of God. Yet God worked even this terrible event for the greatest good - the salvation of all who believe.

Brothers and sisters, whatever trial you may be facing today, whatever disappointment, whatever loss, whatever confusion - remember that our God is sovereign. He is working all things together for your good. Not because you deserve it, but because you are his beloved children, called according to his eternal purpose.

This doesn't mean we should be passive in the face of difficulty. We should still pray, we should still seek wisdom, we should still take appropriate action. But we can do so with confidence, knowing that our heavenly Father is ultimately in control.

As we close this morning, let me encourage you with these words from Jeremiah 29:11: "For I know the plans I have for you," declares the LORD, "plans to prosper you and not to harm you, to give you hope and a future."

Trust in the Lord with all your heart, and lean not on your own understanding. In all your ways acknowledge him, and he will direct your paths.

Let us pray.

Heavenly Father, we thank you for your sovereign care over our lives. Help us to trust you more deeply, especially when we cannot see your hand at work. Give us faith to believe that you are working all things together for our good and your glory. In Jesus' name, Amen.
TRANSCRIPT;

        Storage::put($transcriptPath, $transcriptContent);
        
        $this->command->info('Created mock sermon transcript: ' . $transcriptPath);
    }
}