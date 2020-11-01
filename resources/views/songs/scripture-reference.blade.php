@extends('page')

@section('dynamic_content')

  <p>Please search one verse at a time.</p>

  {{ Form::open(array('url' => '/church/members/songs/scripture-reference')) }}
    <div class="form-group">
      {{ Form::label('book', 'Book') }}
      {{ Form::select('book', 
        array(
          'Gen'   => 'Genesis', 
          'Exod'  => 'Exodus',
          'Lev'   => 'Leviticus',
          'Num'   => 'Numbers',
          'Deut'  => 'Deuteronomy',
          'Josh'  => 'Joshua',
          'Judg'  => 'Judges',
          'Ruth'  => 'Ruth',
          '1Sam'  => '1 Samuel',
          '2Sam'  => '2 Samuel',
          '1Kgs'  => '1 Kings',
          '2Kgs'  => '2 Kings',
          '1Chr'  => '1 Chronicles',
          '2Chr'  => '2 Chronicles',
          'Ezr'   => 'Ezra',
          'Neh'   => 'Nehemiah',
          'Est'   => 'Esther',
          'Job'   => 'Job',
          'Ps'    => 'Psalms',
          'Prov'  => 'Proverbs',
          'Eccl'  => 'Ecclesiastes',
          'Song'  => 'Song of Songs',
          'Isa'   => 'Isaiah',
          'Jer'   => 'Jeremiah',
          'Lam'   => 'Lamentations',
          'Exek'  => 'Exekiel',
          'Dan'   => 'Daniel',
          'Hos'   => 'Hosea',
          'Joel'  => 'Joel',
          'Amos'  => 'Amos',
          'Obed'  => 'Obediah',
          'Jonah' => 'Jonah',
          'Mic'   => 'Micah',
          'Nah'   => 'Nahum',
          'Hab'   => 'Habbukuk',
          'Zeph'  => 'Zephaniah',
          'Hag'   => 'Haggai',
          'Zech'  => 'Zechariah',
          'Mal'   => 'Malachi',
          'Matt'  => 'Matthew',
          'Mark'  => 'Mark',
          'Luke'  => 'Luke',
          'John'  => 'John',
          'Acts'  => 'Acts',
          'Rom'   => 'Romans',
          '1Cor'  => '1 Corinthians',
          '2Cor'  => '2 Corinthians',
          'Gal'   => 'Galatians',
          'Eph'   => 'Ephesians',
          'Phil'  => 'Phillipians',
          'Col'   => 'Colossians',
          '1Thess'=> '1 Thessalonians',
          '2Thess'=> '2 Thessalonians',
          '1Tim'  => '1 Timothy',
          '2Tim'  => '2 Timothy',
          'Titus' => 'Titus',
          'Phm'   => 'Philemon',
          'Heb'   => 'Hebrews',
          'Jas'   => 'James',
          '1Pet'  => '1 Peter',
          '2Pet'  => '2 Peter',
          '1John' => '1 John',
          '2John' => '2 John',
          '3John' => '3 John',
          'Jude'  => 'Jude',
          'Rev'   => 'Revelation'
          ),
        null, 
        array('class' => 'form-control')) }}

      {{ Form::label('chapter', 'Chapter') }}
      {{ Form::number('chapter', 'value', array('class' => 'form-control')) }}

      {{ Form::label('verse', 'Verse') }}
      {{ Form::number('verse', 'value', array('class' => 'form-control')) }}

      <br>
      {{ Form::submit('Search', array('class' => 'btn btn-primary btn-lg btn-block')) }}
    </div>
  {{ Form::close() }}

@stop