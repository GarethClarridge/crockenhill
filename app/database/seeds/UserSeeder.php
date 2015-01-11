<?php
 
class UserSeeder extends Seeder {
 
    public function run()
    {
        Eloquent::unguard();

        DB::table('users')->delete();

        User::create(array(
            'email'     => 'admin@crockenhill.org',
            'password'  => Hash::make('1Cor922'),
            'username'  => 'admin',
            'first_name'=> 'ad',
            'last_name' => 'min',
        ));

        User::create(array(
            'email'     => 'member@crockenhill.org',
            'password'  => Hash::make('1Cor922'),
            'username'  => 'member',
            'first_name'=> 'mem',
            'last_name' => 'ber',
        ));

    }
 
}
