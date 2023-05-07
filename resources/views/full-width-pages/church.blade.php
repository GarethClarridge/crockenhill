@extends('layouts.main')

@section('title')
  Church
@stop

@section('description')
  Church
@stop

@section('content')
  <main>
    <div class="white-background p-5 text-white text-center">
      <h1 class="py-5 text-body full-width-heading">
        Church
      </h1>
    </div>

    <div>
      <div class="white-background">
        <div class="container">
          <div class="row justify-content-md-center">
            <div class="col-md-10 col-lg-8">
              <div class="text-center">
                <p>
                  We're a family of people committed to living together under the authority of the Bible,
                  who exist to worship God, strengthen believers and proclaim the good news of Jesus Christ to all.
                </p>
                <p>
                  There are about 50 of us on a Sunday in person, with more
                  following on online. We're from different nationalities,
                  backgrounds and ages from 5 to 85!
                  The one thing that unites us is our love for Jesus Christ and
                  our gratefulness for his amazing rescue.
                </p>
                <div class="my-5 in-page-nav-buttons">
                  <a class="btn btn-lg btn-primary" href="#when" role="button">When do we meet?</a>
                  <a class="btn btn-lg btn-primary" href="#what" role="button">What kind of church are we?</a>
                  <a class="btn btn-lg btn-primary" href="#who" role="button">Who leads the church?</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="when" class="bg-pattern my-5 p-5 text-white text-center">
      <h2 class="h1">
        When do we meet?
      </h2>
    </div>

    <div class="container">
      <div class="white-background mb-5">

        <div class="row justify-content-md-center">
          <div class="col-md-10 col-lg-8">
            <div class="text-center">
              <p>
                We meet together each Sunday morning at 10:30am to worship God,
                learn from the Bible, and enjoy fellowship with one another.
                We meet again on Sunday evenings
                for a service with a focus on prayer.
                We meet during the week in each other's homes for deeper Bible study,
                applying what God says to our lives.
              </p>
            </div>
          </div>
        </div>

        <div class="row my-5 row-cols-1 row-cols-md-3 g-2 g-lg-3">

          <div class="col mb-4">

            <div class="card p-0">
              <div class="card-img-caption d-flex align-items-center">
                <h3 class="card-text text-white h2">
                  <div class="p-2 lh-sm">
                    Main Sunday service
                  </div>
                </h3>
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/sunday-services.jpg">
              </div>

              <div class="card-body">
                Our main activity as a church is gathering together to worship
                God and hear from his word on Sunday mornings at 10:30am.
                <div class="alert alert-info mt-3">
                  Sunday services are also available live streamed via
                  <a href="https://www.youtube.com/channel/UCtSUTtkZlALToswWQpWS2kA/">
                    our YouTube channel
                  </a>.
                </div>
              </div>
              <div class="card-footer">
                <a href="community/sunday-services">Find out more ...</a>
              </div>
            </div>

          </div>
          <div class="col mb-4">

            <div class="card p-0">
              <div class="card-img-caption d-flex align-items-center">
                <h3 class="card-text text-white h2">
                  <div class="p-2 lh-sm">
                    Sunday prayer service
                  </div>
                </h3>
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
              </div>
              <div class="card-body">
                We meet again on Sunday evenings at 6pm.
                About half of this meeting is given over to prayer for God's
                will to be done in our church, our community and our world.
                The other half includes a more teaching and devotionally
                focussed sermon, and some songs.
              </div>
              <div class="card-footer">
                <a href="community/sunday-services">Find out more ...</a>
              </div>
            </div>

          </div>
          <div class="col mb-4">

            <div class="card p-0">
              <div class="card-img-caption d-flex align-items-center">
                <h3 class="card-text text-white h2">
                  <div class="p-2 lh-sm">
                    Bible studies
                  </div>
                </h3>
                <img class="card-img-top cbc-card-img-top" src="/images/headings/small/bible-study.jpg">
              </div>
              <div class="card-body">
                We meet in small groups in homes each week on
                Tuesday, Wednesday or Thursday to study the bible and pray
                together.
              </div>
              <div class="card-footer">
                <a href="community/bible-study">Find out more ...</a>
              </div>
            </div>

          </div>

        </div>

        <div class="row justify-content-md-center">
          <div class="col-md-10 col-lg-8">
            <div class="text-center">
              <p>
                We usually run groups for everyone from toddlers to pensioners
                so that we can tell as many people as possible the
                amazing news about Jesus and what he's done for us.
              </p>
              <a class="btn btn-lg btn-primary mt-3" href="/community" role="button">
                See other activities &nbsp
                <i class="fas fa-arrow-circle-right"></i>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="what" class="bg-pattern my-5 p-5 text-white text-center">
      <h2 class="h1">
        What kind of church are we?
      </h2>
    </div>

    <div class="container">
      <div class="white-background mb-5">
        <div class="row justify-content-md-center">
          <div class="col-md-10 col-lg-8">
            <div class="text-center">
              <h3 class="my-5">A loving community...</h3>
              <p>
                Church is so much more than what takes place in organised
                groups and services. We want to be a church who support
                one another when times are tough, and who encourage
                one another to live the Christian life 24/7, to grow more
                and more like Jesus, and to show those we rub shoulders
                with every day something of the love of God.
              </p>
              <h3 class="my-5">...of ordinary people...</h3>
              <p>
                Our church is made up of people of all ages and backgrounds.
                In fact, people just like you! So whether you'd call yourself
                a Christian or you're just looking, we'd love to get to know you.
              </p>
              <p>
                We want people to become active followers of Jesus Christ,
                so as a church we provide opportunities for everyone
                to learn, serve and grow in their faith.
              </p>
              <h3 class="my-5">...who have been saved by Jesus,...</h3>
              <p>
                We know that we all naturally rebel against God. We want to
                live life our own way, putting ourselves first. But God
                promises that our way leads to destruction. Thankfully,
                in his infinite love for us, God sent his Son - Jesus - to
                rescue us from certain death.
              </p>
              <p>
                Jesus paid the penalty for our rebellion against God in his
                death on the cross, brought us back to a restored
                relationship with God our Father, and now lives
                in us by his Holy Spirit, guiding our lives day by day.
              </p>
              <h3 class="my-5">...who take the Bible seriously,...</h3>
              <p>
                If you come to our services you'll notice we spend a lot of
                time reading and explaining the Bible. We believe the Bible
                is God's perfect word to us - it's how he speaks into our
                everyday lives. When we hear the Bible preached God speaks
                to our hearts by his Spirit and forms us to be more and more
                like Jesus.
              </p>
              <p>
                We want the Bible to inform everything we do. From our
                praying and singing on Sunday to working in the office on
                Monday. From bringing up our children to loving our friends.
              </p>
              <h3 class="my-5">...and who want to tell everyone about Jesus.</h3>
              <p>
                We know we've been given a wonderful gift by God, and we
                don't want to keep it to ourselves! Much of our energy as a
                church is spent on telling people the good news that they
                too can be rescued from death and brought into the eternal
                life of Christ.
              </p>
              <a class="btn btn-lg btn-primary my-3" href="/christ">
                Find our more about the good news of Jesus Christ &nbsp
                <i class="fas fa-arrow-circle-right"></i>
              </a>
              <hr class="my-5">
              <h3 class="h2 mb-5">
                An <i>evangelical</i> church
              </h3>
              <p>
                We believe that the gospel
                (<i>evangel</i> in Latin) is the
                good news of salvation for all people through Jesus Christ.
              </p>
              <p>
                We believe that the gospel centers on the death of Jesus
                Christ on the cross in our place.
              </p>
              <p>
                We believe that the gospel is revealed in the Bible,
                God's perfect word to us.
              </p>
              <p>
                For more detail on what we believe, see our
                <a href="/church/statement-of-faith">
                  statement of faith
                </a>.
              </p>
              <a class="btn btn-lg btn-primary my-3" href="/church/statement-of-faith">
                Statement of faith &nbsp
                <i class="fas fa-arrow-circle-right"></i>
              </a>
              <hr class="my-5">
              <h3 class="h2 mb-5">
                A <i>baptist</i> church
              </h3>
              <p>
                Our church started life as "Union Chapel", a mixture
                of Baptists and Congregationalists.
              </p>
              <a class="btn btn-lg btn-primary my-3" href="/church/history">
                Find out more about our history &nbsp
                <i class="fas fa-arrow-circle-right"></i>
              </a>
              <p>
                These days we're a Baptist church. That means we think the
                Bible teaches adults should be baptised (the word just means
                dunked in water) when they come to trust in Jesus Christ
                for salvation. Unlike other churches, we don't baptise
                babies as they can't tell us whether or not they're trusting
                Jesus!
              </p>
              <hr class="my-5">
              <h3 class="h2 mb-5">
                An <i>independent</i> church
              </h3>
              <p>
                Despite being a Baptist church, we're not a member of
                <a href="https://www.baptist.org.uk/">
                  the Baptist Union
                </a> (for theological reasons)
                or
                <a href="https://www.gracebaptists.org/">
                  the Association of Grace Baptist Churches
                </a>.
              </p>
              <p>
                In fact, we're not part of any denomination. We are run and
                entirely finacially supported by our members: ordinary local
                people.
              </p>
              <p>
                We don't want to isolate ourselves from Christians in other
                places though. We are affiliated with
                <a href="https://fiec.org.uk/">
                  the Fellowship of Evangelical Churches
                </a>, a nationwide group of like-minded churches.
              </p>
              <p>
                We also have links with other local churches and Christian
                organisations.
              </p>
              <a class="btn btn-lg btn-primary my-3" href="/church/links">
                See who we're linked with &nbsp
                <i class="fas fa-arrow-circle-right"></i>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="who" class="bg-pattern my-5 p-5 text-white text-center">
      <h2 class="h1">
        Who leads the church?
      </h2>
    </div>


    <div class="container">
      <div class="row justify-content-md-center">
        <div class="col-md-12">
          <div class="white-background mb-5">

            <div class="row justify-content-md-center">
              <div class="col-md-10 col-lg-8">
                <h3 class="h2 mb-5">
                  Our pastor
                </h3>

                <img src="/images/headings/small/pastor.jpg" alt="Pastor Mark Drury" class=mb-5>

                <p>
                  Our pastor, Mark Drury, has been at the church since 2011.
                  Mark works full-time for the church.
                </p>

                <a class="btn btn-lg btn-primary my-5" href="/church/pastor">
                  Find out more about Mark &nbsp
                  <i class="fas fa-arrow-circle-right"></i>
                </a>
                <h3 class="h2 mb-5">
                  Our leadership
                </h3>
                <p>
                  Alongside Mark, the church is led by two elders, Laurie and Peter.
                </p>
                <p>
                  Practical matters are handled by the deacons, adminstrator and treasurer.
                </p>
                <h3 class="h2 my-5">
                  Our policies
                </h3>
                <p>
                  In order to care for the members of the church and visitors to
                  its activities, the church has adopted a number of policies:
                </p>
              </div>
            </div>

            <div class="row my-5 row-cols-1 row-cols-md-3 g-2 g-lg-3">

              <div class="col mb-4">
                <div class="card p-0">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2 lh-sm">
                        Safeguarding policy
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
                  </div>
                  <div class="card-body">
                    Our safeguarding policy outlines how we work to keep
                    children and vulnerable adults safe.
                  </div>
                  <div class="card-footer">
                    <a href="safeguarding-policy">Find out more ...</a>
                  </div>
                </div>
              </div>

              <div class="col mb-4">
                <div class="card p-0">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2 lh-sm">
                        Positive behaviour policy
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
                  </div>
                  <div class="card-body">
                    Our positive behaviour policy guides how we make sure our
                    childrens' groups are safe and fun for all involved.
                  </div>
                  <div class="card-footer">
                    <a href="/media/documents/BehaviourPolicy.pdf">Find out more ...</a>
                  </div>
                </div>
              </div>

              <div class="col mb-4">
                <div class="card p-0">
                  <div class="card-img-caption d-flex align-items-center">
                    <h3 class="card-text text-white h2">
                      <div class="p-2 lh-sm">
                        Privacy policy
                      </div>
                    </h3>
                    <img class="card-img-top cbc-card-img-top" src="/images/headings/small/default.jpg">
                  </div>
                  <div class="card-body">
                    Our privacy policy outlines how we use data and keep it safe.
                  </div>
                  <div class="card-footer">
                    <a href="privacy-policy">Find out more ...</a>
                  </div>
                </div>
              </div>

            </div>

          </div>
        </div>
      </div>
    </div>

  </main>

@stop
