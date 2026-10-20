<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

class Office extends BaseController
{
    public function getIndex(): RedirectResponse
    {
        return redirect()->to('home');
    }
}
