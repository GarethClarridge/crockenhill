<?php

namespace Database\Seeders;

class PermissionSeeder extends Seeder {

  public function run()
  {
    $managePages = new Permission;
    $managePages->name = 'manage_pages';
    $managePages->display_name = 'Manage Pages';
    $managePages->save();

    $manageUsers = new Permission;
    $manageUsers->name = 'manage_users';
    $manageUsers->display_name = 'Manage Users';
    $manageUsers->save();

    $manageSermons = new Permission;
    $manageSermons->name = 'manage_sermons';
    $manageSermons->display_name = 'Manage Sermons';
    $manageSermons->save();
  }
}
