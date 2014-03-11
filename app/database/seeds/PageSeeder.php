<?php

class PageSeeder extends Seeder {

    public function run()
    {
    
        DB::table('pages')->delete();
    
        Page::create(array(
            'slug'      => 'about',
            'heading'   => 'About Us',
            'body'      => '<p>We are a Bible-believing village church on the outskirts of greater London and just within the M25, and are committed to living under the authority of the Bible. There are about 50-70 of us on a Sunday morning, including children and young people.</p>
<p>We regularly come together in order to worship God, learn from the Bible, and enjoy fellowship with one another.  However, church life amounts to much more than what takes place on a Sunday or at other specific times during the week when church activities are held.  We want to encourage one another to live the Christian life 24/7 and to show to those whom we rub shoulders with everyday something of the love of God.</p>
<p>As a church we encourage people to become active followers of Jesus Christ, and provide opportunities for them to learn, serve and grow in their faith.  We welcome people of all ages and backgrounds to find out more about the good news of Jesus Christ, who by his death and resurrection has rescued people from their sin to life eternal.</p>'
        ));
    }
    
}
