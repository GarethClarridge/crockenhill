<?=
'<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL
?>
<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"  xmlns:content="http://purl.org/rss/1.0/modules/content/">
    <channel>
        <title>Sunday evenings at Crockenhill Baptist Church</title>
        <itunes:owner>
            <itunes:email>admin@crockenhill.org</itunes:email>
        </itunes:owner>
        <itunes:author>Crockenhill Baptist Church</itunes:author>
        <link>http://crockenhill.org/church/sermons/evening</link>
        <itunes:summary>Sermons from Sunday evenings at Crockenhill Baptist Church</itunes:summary>
        <itunes:category text="Religion &amp; Spirituality"/>
        <itunes:explicit>no</itunes:explicit>
        <itunes:image href="http://crockenhill.org/public/images/podcast/EveningArtwork.png"/>
        <language>en-gb</language>
        <pubDate>{{ now() }}</pubDate>

        @foreach($sermons as $sermon)
            <item>
                <title><![CDATA[{{ $sermon->title }}]]></title>
                <link>http://crockenhill.org/church/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}</link>
                <description>
                  A sermon on <![CDATA[{!! $sermon->reference !!}]]> from <![CDATA[{!! $sermon->preacher !!}]]> as part of our <![CDATA[{!! $sermon->series !!}]]> series.
                </description>
                <enclosure url="http://crockenhill.org/media/sermons/{{ $sermon->filename }}.mp3"
                           type="audio/mpeg" length="{{ $sermon->duration }}"/>
                <itunes:duration>{{ $sermon->duration }}</itunes:duration>
                <guid isPermaLink="false">{{ $sermon->id }}</guid>
                <pubDate>{{ $sermon->created_at }}</pubDate>
            </item>
        @endforeach
    </channel>
</rss>
