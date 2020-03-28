@extends('layouts.main')

@section('title')
  Online
@stop

@section('description')
  Online services at Crockenhill Baptist Church
@stop

@section('content')
  <main id="online-page">
    <div class="online-head text-white">
      <div class="container mt-3">
        <div class="row justify-content-lg-center">
          <div class="col-lg-12">
            <article class="card">
              <div class="card-img-caption d-flex align-items-center">
                <h1 class="card-text text-white">
                  <div class="px-2 py-1">
                    Sunday 22 March
                  </div>
                </h1>
                @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
                  <img class="card-img-top cbc-card-img-top" src="{{$headingpicture}}">
                @else
                  <img class="card-img-top cbc-card-img-top" src="/images/headings/large/default.jpg">
                @endif
              </div>
              <div class="card-body">
                <div class="embed-responsive embed-responsive-16by9">
                  <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/CJ93dRruSyw" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
              </div>
            </article>
          </div>
        </div>

        <div class="row justify-content-md-center mt-3">
          <div class="col-md-6">
            <article class="card">
              <div class="card-body">
                <div class="embed-responsive embed-responsive-16by9">
                  <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/-eIQQayhpak" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
              </div>
            </article>
          </div>
          <div class="col-md-6">
            <article class="card">
              <div class="card-body">
                <div class="embed-responsive embed-responsive-16by9">
                  <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/4eMO5aut7eI" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
              </div>
            </article>
          </div>
        </div>

        <div class="row justify-content-lg-center mt-3">
          <div class="col-lg-8">
            <article class="card">
              <div class="card-body text-dark">
                <h3 class="card-title text-center mb-3">
                  Today's Bible reading: John 14:1-7
                </h3>
                <h5>
                  Jesus Comforts His Disciples
                </h5>
                <p>
                  <sup>1</sup>“Do not let your hearts be troubled. You believe in God; believe also in me.
                  <sup>2</sup>My Father’s house has many rooms; if that were not so, would I have told you that I am going there to prepare a place for you?
                  <sup>3</sup>And if I go and prepare a place for you, I will come back and take you to be with me that you also may be where I am.
                  <sup>4</sup>You know the way to the place where I am going.”
                </p>
                <h5>
                  Jesus the Way to the Father
                </h5>
                <p>
                  <sup>5</sup>Thomas said to him, “Lord, we don’t know where you are going, so how can we know the way?”
                </p>
                <p>
                  <sup>6</sup>Jesus answered, “I am the way and the truth and the life. No one comes to the Father except through me.
                  <sup>7</sup>If you really know me, you will know my Father as well. From now on, you do know him and have seen him.”
                </p>
              </div>
              <div class="card-footer text-muted">
                  New International Version (NIV)
                  <br>
                  Holy Bible, New International Version®,
                  NIV® Copyright ©1973, 1978, 1984, 2011 by
                  <a href="www.biblica.com">Biblica, Inc.®</a>
                  Used by permission.
                  All rights reserved worldwide.
              </div>
            </article>
          </div>
        </div>

        <!-- <div class="row justify-content-md-center">
          <div class="col-md-8">
            <article class="card mt-3">
              <img class="card-img-top" src="../images/homepage/SundayServiceComingSoon.png" alt="Sunday service coming soon">
              <div class="card-body">
                <h5 class="card-title text-center text-dark">Sunday 22 March</h5>
                <p class="card-text text-center text-dark">An online service is coming soon...</p>
              </div>
            </article>
          </div>
        </div> -->
      </div>
    </div>
  </main>
@stop
