<?php

class AuthController extends BaseController {
 
        /**
         * Display the login page
         * @return View
         */
        public function getLogin()
        {
                return View::make('members.auth.login');
        }
 
        /**
         * Login action
         * @return Redirect
         */
        public function postLogin()
        {
                $credentials = array(
                        'email' => Input::get('email'),
                        'password' => Input::get('password')
                );
 
                if (Auth::attempt($credentials))
                {
                    return Redirect::intended('members/homepage');
                }
                
                else 
                {
                    return Redirect::route('members.login')->withErrors(array(
                            'login' => 'Sorry, that combination doesn\'t seem to be correct. Please try again!'
                        ));
                }
        }
 
        /**
         * Logout action
         * @return Redirect
         */

        public function getLogout()
        {
                Auth::logout();
 
                return Redirect::route('members.login');
        }
 
}
