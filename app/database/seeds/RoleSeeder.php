<?php
 
class RoleSeeder extends Seeder {
 
    public function run()
    {
        DB::table('roles')->delete();
 
        Role::create(array(
            'name' => 'admin',
        ));
        
        // Seed the users_roles table
        
        $adminRole = Role::where('name','=','Admin')->first()->id;
        $gareth = User::where('username','=','Gareth.Clarridge')->first()->id;
        $role_user = array(
            array('role_id' => $adminRole, 'user_id' => $gareth)
        );

        // Uncomment the below to run the seeder
        DB::table('users_roles')->insert($role_user);

    }
 
}
