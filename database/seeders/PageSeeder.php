<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
  public function run()
  {
    $pages = [
      [
        'slug' => 'home',
        'heading' => 'Welcome to Crockenhill Baptist Church',
        'description' => 'A warm welcome to our church website',
        'area' => 'main',
        'body' => 'Welcome to Crockenhill Baptist Church. We are a Bible-believing church committed to the gospel of Jesus Christ.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'about',
        'heading' => 'About Us',
        'description' => 'Learn more about our church and beliefs',
        'area' => 'main',
        'body' => 'Our church has been part of the Crockenhill community for many years...',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'contact',
        'heading' => 'Contact Us',
        'description' => 'Get in touch with our church',
        'area' => 'main',
        'body' => 'We would love to hear from you. Please feel free to contact us...',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'sunday-mornings',
        'heading' => 'Sunday Mornings',
        'description' => 'Information about our Sunday morning services.',
        'area' => 'community', // Or appropriate area
        'body' => 'Join us for our Sunday morning services at 10:30 AM.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'sunday-evenings',
        'heading' => 'Sunday Evenings',
        'description' => 'Information about our Sunday evening services.',
        'area' => 'community', // Or appropriate area
        'body' => 'Join us for our Sunday evening services at 6 PM.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'bible-study',
        'heading' => 'Bible Study',
        'description' => 'Details about our Bible study groups.',
        'area' => 'community', // Or appropriate area
        'body' => 'Explore the scriptures with us in our Bible study groups.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'coffee-cup',
        'heading' => 'Coffee Cup',
        'description' => 'Join us for coffee and a chat.',
        'area' => 'community',
        'body' => 'Details about Coffee Cup.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'baby-talk',
        'heading' => 'Baby Talk',
        'description' => 'A group for parents and babies.',
        'area' => 'community',
        'body' => 'Details about Baby Talk.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'family-talk',
        'heading' => 'Family Talk',
        'description' => 'A group for families.',
        'area' => 'community',
        'body' => 'Details about Family Talk.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'buzz-club',
        'heading' => 'Buzz Club',
        'description' => 'Activities for children.',
        'area' => 'community',
        'body' => 'Details about Buzz Club.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'christianity-explored',
        'heading' => 'Christianity Explored',
        'description' => 'Find out more about the Christian faith.',
        'area' => 'community',
        'body' => 'Details about Christianity Explored courses.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'carols-in-the-chequers',
        'heading' => 'Carols in the Chequers',
        'description' => 'Our annual carol event.',
        'area' => 'community',
        'body' => 'Details about Carols in the Chequers.',
        'admin' => 'no',
        'navigation' => true,
      ],
      [
        'slug' => 'admin-dashboard',
        'heading' => 'Admin Dashboard',
        'description' => 'Administrative area',
        'area' => 'admin',
        'body' => 'Administrative functions and controls',
        'admin' => 'yes',
        'navigation' => false,
      ],
    ];

    foreach ($pages as $page) {
      Page::create($page);
    }

    Page::factory(6)->create();
  }
}
