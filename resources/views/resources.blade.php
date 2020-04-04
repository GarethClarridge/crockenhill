@extends('layouts.main')

@section('title')
  Resources
@stop

@section('description')
  Resources for isolation
@stop

@section('content')
  <main class="container mb-3">
    <div class="row justify-content-md-center">
      <div class="col-md-10 col-lg-9">
        <article class="card mt-3">
          <div class="card-img-caption d-flex align-items-center">
            <h1 class="card-text text-white">
              <div class="px-2 py-1">
                Resources
              </div>
            </h1>
            <img class="card-img-top cbc-card-img-top" src="/images/headings/large/default.jpg">
          </div>

          <div class="card-body">
            <div class="main-content">
              <h2>Prayer</h2>
                <p>
                  It may be that we find it challenging to know exactly how we should pray in the midst of this coronavirus.
                  Mark hash provided us with some suggestions for things to pray about:
                </p>
                <ul>
                  <li>Our Prime Minister and his government as they seek to guide the nation through this difficult time.</li>
                  <li>Our NHS and care workers around the country that are on the front-line.</li>
                  <li>For those in countries where there is little  or no medical treatment.</li>
                  <li>For those who are anxious and fearful that they might know God’s peace.</li>
                  <li>For pastors who are seeking to lead their flocks but find themselves restricted to their homes.</li>
                  <li>For families who have lost loved ones.</li>
                  <li>For God’s will, whatever that may be, to be done.</li>
                  <li>For God’s people to learn to trust in Him more.</li>
                  <li>For opportunities to speak about Jesus.</li>
                  <li>For those who have no real hope in life to find Jesus.</li>
                </ul>

              <h2>Teaching</h2>
                <p>
                  One good thing to come out of this pandemic is the
                  opportunity to virtually visit lots of other churches!
                </p>
                <p>
                  The FIEC have helpfully compiled
                  <a href="https://fiec.org.uk/resources/fiec-churches-serving-online">
                    a list of churches that are broadcasting their Sunday services.
                </a> Do check it out (and see if you can spot us).
                </p>
                <p>
                  <a href="https://fiec.org.uk/resources/fiec-churches-serving-online" class="btn btn-secondary" id="online-service-btn">
                    FIEC Churches Serving Online
                  </a>
                </p>
                <p>
                  There are lots of other places we can go online for good
                  Bible teaching. Here's a few recommendations:
                </p>

                <div class="container">
                  <div class="row justify-content-md-center">
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/FIEC.jpg" class="card-img-top" alt="FIEC">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://fiec.org.uk/">
                              FIEC
                            </a>
                          </h5>
                          <p class="card-text">
                            We're part of this group of independent churches
                            working together to reach Britain for Christ by
                            growing gospel churches. The FIEC have lots of
                            resources on their website, from articles about
                            what local churches are doing to conference talks.
                          </p>
                          <a href="https://fiec.org.uk/" class="btn btn-primary">
                            Go to FIEC
                          </a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/TGC.jpg" class="card-img-top" alt="TGC">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://www.thegospelcoalition.org">The Gospel Coalition</a>
                          </h5>
                          <p class="card-text">
                            The Gospel Coalition is a predominantly American group
                            of reformed evangelical churches. They put out a lot
                            of accessible content, and have a vast collection of
                            sermons from the last 30 years on their website.
                          </p>
                          <a href="https://www.thegospelcoalition.org" class="btn btn-primary">
                            Go to The Gospel Coalition
                          </a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/DesiringGod.jpg" class="card-img-top" alt="Desiring God">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://www.desiringgod.org">Desiring God</a>
                          </h5>
                          <p class="card-text">
                            Desiring God is the ministry set up by John Piper to
                            proclaim the truth that God is most glorified in us
                            when we are most satisfied in him. They have lots of
                            helpful articles, particularly about dealing with
                            times of suffering like we're in now.
                          </p>
                          <a href="https://www.desiringgod.org" class="btn btn-primary">
                            Go to Desiring God
                          </a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/ChristianityExplored.jpg" class="card-img-top" alt="Christianity Explored">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://www.christianityexplored.org/">Christianity Explored</a>
                          </h5>
                          <p class="card-text">
                            The Christianity Explored website has a lot of good
                            'apologetics' content: explaining why belief in God
                            is perfectly reasonable, even in a time of suffering
                            and uncertainty.
                          </p>
                          <a href="https://www.christianityexplored.org/" class="btn btn-primary">Go to Christianity Explored</a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/WordAlive.jpg" class="card-img-top" alt="Word Alive">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://wordaliveevent.org">Word Alive</a>
                          </h5>
                          <p class="card-text">
                            Word Alive, an annual Bible teaching holiday, had
                            to be cancelled this year due to coronavirus, but
                            they're making past recordings available for free
                            on a Sunday.
                          </p>
                          <a href="https://wordaliveevent.org" class="btn btn-primary">Go to Word Alive</a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/SpeakLife.jpg" class="card-img-top" alt="Speak Life">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://speaklife.org.uk/">Speak Life</a>
                          </h5>
                          <p class="card-text">
                            Over the last 8 years, Speak Life has produced
                             hundreds of video resources that seek to proclaim
                             the gospel in a modern and relevant way.
                          </p>
                          <a href="https://speaklife.org.uk/" class="btn btn-primary">Go to Speak Life</a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/Ligonier.jpg" class="card-img-top" alt="Ligonier">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://www.ligonier.org/">Ligonier</a>
                          </h5>
                          <p class="card-text">
                            Ligonier Ministries, founded by R.C. Sproul,
                            exists to proclaim, teach, and defend the holiness of God
                            in all its fullness to as many people as possible.
                            They've made many of their video lectures free to access
                            during the coronavirus pandemic.
                          </p>
                          <a href="https://www.ligonier.org/" class="btn btn-primary">Go to Ligonier</a>
                        </div>
                      </div>
                    </div>
                    <div class="col-md-4 mb-1">
                      <div class="card">
                        <img src="/images/resources/Clayton.jpg" class="card-img-top" alt="Clayton TV">
                        <div class="card-body">
                          <h5 class="card-title">
                            <a href="https://www.clayton.tv">Clayton TV</a>
                          </h5>
                          <p class="card-text">
                            A website produced by Jesmond Parish Church in Newcastle,
                            which broadcasts free Bible teaching from several evangelical
                            Anglican churches and as well known conferences like
                            the Keswick Convention.
                          </p>
                          <a href="https://www.clayton.tv" class="btn btn-primary">Go to Clayton TV</a>
                        </div>
                      </div>
                    </div>
                  </div>

                </div>


              <div class="clearfix"></div>
            </div>
          </div>
        </article>
      </div>
    </div>
  </main>
@stop
