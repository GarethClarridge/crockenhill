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
