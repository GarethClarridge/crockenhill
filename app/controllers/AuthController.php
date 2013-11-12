<?php

class AuthController extends BaseController {

    public function doLogin() {

        if (Input::get('password') === 'Proverbs35')
        {
            $user = User::find(1);
            Auth::login($user);
            return Redirect::to('/membersarea');
        } else {

            return Redirect::to('/membersarea/login');

        }

    }

}
