<?php
 
class RoleSeeder extends Seeder {
 
    public function run()
    {
        Eloquent::unguard();

        DB::table('roles')->delete();
 
        Role::create(array(
            'name' => 'admin',
        ));
        
        // Seed the users_roles table
        
        $adminRole = Role::where('name','=','Admin')->first()->id;
        $admin = User::where('username','=','admin')->first()->id;
        $role_user = array(
            array('role_id' => $adminRole, 'user_id' => $admin)
        );

        // Uncomment the below to run the seeder
        DB::table('users_roles')->insert($role_user);

    }
 
}
