<?= '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL ?>
<rss version="2.0"
     xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:atom="http://www.w3.org/2005/Atom"
     xmlns:podcast="https://podcastindex.org/namespace/1.0">
  <channel>
    <title>{{ $metadata['title'] }}</title>
    <atom:link href="{{ $metadata['feed_url'] }}" rel="self" type="application/rss+xml" />
    <itunes:owner>
      <itunes:name>{{ $metadata['owner_name'] }}</itunes:name>
      <itunes:email>{{ $metadata['owner_email'] }}</itunes:email>
    </itunes:owner>
    <itunes:author>{{ $metadata['author'] }}</itunes:author>
    <link>{{ $metadata['link'] }}</link>
    <itunes:summary>{{ $metadata['description'] }}</itunes:summary>
    <description>{{ $metadata['description'] }}</description>
    <itunes:category text="{{ $metadata['category'] }}">
      <itunes:category text="{{ $metadata['subcategory'] }}" />
    </itunes:category>
    <itunes:explicit>false</itunes:explicit>
    <itunes:type>episodic</itunes:type>
    <image>
      <link>{{ $metadata['link'] }}</link>
      <title>{{ $metadata['title'] }}</title>
      <url>{{ $metadata['image'] }}</url>
    </image>
    <itunes:image href="{{ $metadata['image'] }}" />
    <itunes:new-feed-url>{{ $metadata['feed_url'] }}</itunes:new-feed-url>
    <language>{{ $metadata['language'] }}</language>
    <pubDate>{{ now()->toRfc2822String() }}</pubDate>
    <podcast:locked>no</podcast:locked>
    <podcast:guid>{{ $metadata['podcast_guid'] }}</podcast:guid>

    @foreach($feedItems as $feedItem)
    <item>
      <title><![CDATA[{{ $feedItem['sermon']->title }}]]></title>
      <itunes:title><![CDATA[{{ $feedItem['sermon']->title }}]]></itunes:title>
      <itunes:author>{{ $metadata['author'] }}</itunes:author>
      <itunes:image href="{{ $feedItem['episode_image_url'] ?? $metadata['image'] }}" />
      <link>{{ $feedItem['canonical_url'] }}</link>
      <itunes:summary><![CDATA[{{ $feedItem['podcast_summary'] }}]]></itunes:summary>
      <itunes:subtitle><![CDATA[{{ $feedItem['podcast_summary'] }}]]></itunes:subtitle>
      <description><![CDATA[{{ $feedItem['podcast_summary'] }}]]></description>
      <enclosure
        url="{{ $feedItem['enclosure_url'] }}"
        type="audio/mpeg"
        length="{{ $feedItem['enclosure_length'] }}" />
      <itunes:duration>{{ $feedItem['itunes_duration'] }}</itunes:duration>
      <guid isPermaLink="false">sermon-{{ $feedItem['sermon']->id }}</guid>
      <pubDate>{{ $feedItem['rss_pub_date'] }}</pubDate>
      <itunes:explicit>false</itunes:explicit>
      <itunes:episodeType>full</itunes:episodeType>
@if($feedItem['transcript_url'])
      <podcast:transcript url="{{ $feedItem['transcript_url'] }}" type="text/plain" />
@endif
    </item>
    @endforeach

  </channel>
</rss>
