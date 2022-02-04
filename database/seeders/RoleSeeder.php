<?php

namespace Database\Seeders;

class RoleSeeder extends Seeder {

  public function run()
  {
    $admin = new Role;
    $admin->name = 'Admin';
    $admin->save();

    $managePages = Permission::where('name','=','manage_pages')->first();
    $manageUsers = Permission::where('name','=','manage_users')->first();
    $manageSermons = Permission::where('name','=','manage_sermons')->first();

    $admin->perms()->sync(array(
      $managePages->id,
      $manageUsers->id,
      $manageSermons->id
    ));

    $member = new Role;
    $member->name = 'Member';
    $member->save();
  }
}
