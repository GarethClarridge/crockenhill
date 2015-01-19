<?php

class UserTableSeeder extends Seeder {

  public function run()
  {
    $admin = new User;
    $admin->username = 'admin';
    $admin->email = 'admin@crockenhill.org';
    $admin->password = '1Cor922';
    $admin->password_confirmation = '1Cor922';
    $admin->confirmation_code = md5(uniqid(mt_rand(), true));

    if(! $admin->save()) {
      Log::info('Unable to create user '.$admin->username, (array)$admin->errors());
    } else {
      Log::info('Created user "'.$admin->username.'" <'.$admin->email.'>');
    }

    $member = new User;
    $member->username = 'member';
    $member->email = 'member@crockenhill.org';
    $member->password = '1Cor922';
    $member->password_confirmation = '1Cor922';
    $member->confirmation_code = md5(uniqid(mt_rand(), true));

    if(! $member->save()) {
      Log::info('Unable to create user '.$member->username, (array)$member->errors());
    } else {
      Log::info('Created user "'.$member->username.'" <'.$member->email.'>');
    }
  }
}