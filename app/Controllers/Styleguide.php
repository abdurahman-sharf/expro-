<?php

namespace App\Controllers;

class Styleguide extends Secure_Controller
{
    public function __construct()
    {
        parent::__construct('config');
    }

    public function getIndex(): string
    {
        return view('styleguide/index');
    }
}
