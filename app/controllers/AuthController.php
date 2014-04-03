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
                            'login' => 'Sorry, we couldn\'t find those details. Please try again!'
                        ));
                }
        }
 
        /**
         * Logout action
         * @return Redirect
         */
        public function getLogout()
        {
                Sentry::logout();
 
                return Redirect::route('members.login');
        }
 
}
