<?php namespace App\Services\Validators;
 
class SermonValidator extends Validator {
 
    public static $rules = array(
        'title' => 'required',
        'body'  => 'required',
    );
 
}
