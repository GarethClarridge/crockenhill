<?php
 
use App\Models\User;
 
class SentrySeeder extends Seeder {
 
    public function run()
    {
        Eloquent::unguard();
        DB::table('users')->delete();
        DB::table('groups')->delete();
        DB::table('users_groups')->delete();
 
        Sentry::getUserProvider()->create(array(
            'email'       => 'admin@admin.com',
            'password'    => "admin",
            'first_name'  => 'Gareth',
            'last_name'   => 'Clarridge',
            'activated'   => 1,
        ));
 
        Sentry::getGroupProvider()->create(array(
            'name'        => 'Admin',
            'permissions' => array('admin' => 1),
        ));
 
        // Assign user permissions
        $adminUser  = Sentry::getUserProvider()->findByLogin('admin@admin.com');
        $adminGroup = Sentry::getGroupProvider()->findByName('Admin');
        $adminUser->addGroup($adminGroup);
    }
 
}
