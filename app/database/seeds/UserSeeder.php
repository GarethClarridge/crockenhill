<?php
 
class UserSeeder extends Seeder {
 
    public function run()
    {
        DB::table('users')->delete();

        User::create(array(
            'email'     => 'garethclarridge@hotmail.co.uk',
            'password'  => Hash::make('password'),
            'username'  => 'Gareth.Clarridge',
            'first_name'=> 'Gareth',
            'last_name' => 'Clarridge',
        ));

    }
 
}
