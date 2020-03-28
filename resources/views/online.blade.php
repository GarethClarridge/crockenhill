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
<<<<<<< HEAD

=======
>>>>>>> 10dcd783a3d9a3fce334a12f3365f03982f912c6
        <div class="row justify-content-lg-center">
          <div class="col-lg-12">
            <article class="card">
              <div class="card-img-caption d-flex align-items-center">
                <h1 class="card-text text-white">
                  <div class="px-2 py-1">
<<<<<<< HEAD
                    Sunday 29 March
                  </div>
                </h1>
                @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
                  <img class="card-img-top cbc-card-img-top" src="{{$headingpicture}}">
                @else
                  <img class="card-img-top cbc-card-img-top" src="/images/headings/large/default.jpg">
                @endif
              </div>
              <div class="card-body">
                <div class="text-dark">
                  <p>
                    This week the service is split up into 5 videos,
                    all in a YouTube playlist so they'll
                    play one after the other. Press play below to begin.
                  </p>
                  <p>
                    At the beginning Mark refers to some prayer points
                    and links to other websites. You can see these at
                    <a href="/resources">crockenhill.org/resources</a>.
                  </p>
                </div>
                <div class="embed-responsive embed-responsive-16by9">
                  <iframe class="embed-responsive-item" src="https://www.youtube.com/embed/videoseries?list=PLvRlbyvqJwk55dTcMFXxgftVIrgTWvlot" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
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
                  Today's Bible reading: Acts 4:1-22
                </h3>
                <h5>
                  Peter and John Before the Sanhedrin
                </h5>
                <p>
                  <sup>1</sup>The priests and the captain of the temple guard
                  and the Sadducees came up to Peter and John while they were speaking to the people.
                  <sup>2</sup>They were greatly disturbed because the apostles
                  were teaching the people, proclaiming in Jesus the resurrection of the dead.
                  <sup>3</sup>They seized Peter and John and, because it was evening,
                  they put them in jail until the next day.
                  <sup>4</sup>But many who heard the message believed;
                  so the number of men who believed grew to about five thousand.
                </p>
                <p>
                  <sup>5</sup>The next day the rulers,
                  the elders and the teachers of the law met in Jerusalem.
                  <sup>6</sup>Annas the high priest was there, and so were Caiaphas,
                  John, Alexander and others of the high priest’s family.
                  <sup>7</sup>They had Peter and John brought before them and began to question them:
                  “By what power or what name did you do this?”
                </p>
                <p>
                  <sup>8</sup>Then Peter, filled with the Holy Spirit, said to them:
                  “Rulers and elders of the people!
                  <sup>9</sup>If we are being called to account today for an act of kindness
                  shown to a man who was lame and are being asked how he was healed,
                  <sup>10</sup>then know this, you and all the people of Israel:
                  It is by the name of Jesus Christ of Nazareth,
                  whom you crucified but whom God raised from the dead,
                  that this man stands before you healed.
                  <sup>11</sup>Jesus is
                </p>
                <blockquote class="blockquote">
                  <p>
                    “‘the stone you builders rejected, which has become the cornerstone.’
                  </p>
                  <footer class="blockquote-footer">
                    <cite title="Psalm 118:22">Psalm 118:22</cite>
                  </footer>
                </blockquote>
                <p>
                  <sup>12</sup>Salvation is found in no one else,
                  for there is no other name under heaven given to mankind
                  by which we must be saved.”
                </p>
                <p>
                  <sup>13</sup>When they saw the courage of Peter and John
                  and realized that they were unschooled, ordinary men,
                  they were astonished and they took note that these men had been with Jesus.
                  <sup>14</sup>But since they could see the man who had been
                  healed standing there with them, there was nothing they could say.
                  <sup>15</sup>So they ordered them to withdraw from the Sanhedrin
                  and then conferred together.
                  <sup>16</sup>“What are we going to do with these men?” they asked.
                  “Everyone living in Jerusalem knows they have performed a notable sign,
                  and we cannot deny it.
                  <sup>17</sup>But to stop this thing from spreading any further among the people,
                  we must warn them to speak no longer to anyone in this name.”
                </p>
                <p>
                  <sup>18</sup>Then they called them in again
                  and commanded them not to speak or teach at all in the name of Jesus.
                  <sup>19</sup>But Peter and John replied,
                  “Which is right in God’s eyes: to listen to you, or to him?
                  You be the judges!
                  <sup>20</sup>As for us,
                  we cannot help speaking about what we have seen and heard.”
                </p>
                <p>
                  <sup>21</sup>After further threats they let them go.
                  They could not decide how to punish them,
                  because all the people were praising God for what had happened.
                  <sup>22</sup>For the man who was miraculously healed
                  was over forty years old.
                </p>
              </div>
              <div class="card-footer text-muted">
                <small>
                  New International Version (NIV)
                  <br>
                  Holy Bible, New International Version®,
                  NIV® Copyright ©1973, 1978, 1984, 2011 by
                  <a href="www.biblica.com">Biblica, Inc.®</a>
                  Used by permission.
                  All rights reserved worldwide.
                </small>
              </div>
            </article>
          </div>
        </div>

        <div class="row justify-content-lg-center mt-5">
          <div class="col-lg-12">
            <article class="card">
              <div class="card-img-caption d-flex align-items-center">
                <h1 class="card-text text-white">
                  <div class="px-2 py-1">
=======
>>>>>>> 10dcd783a3d9a3fce334a12f3365f03982f912c6
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
                <small>
                  New International Version (NIV)
                  <br>
                  Holy Bible, New International Version®,
                  NIV® Copyright ©1973, 1978, 1984, 2011 by
                  <a href="www.biblica.com">Biblica, Inc.®</a>
                  Used by permission.
                  All rights reserved worldwide.
                </small>
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
