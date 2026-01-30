<?php

namespace App\Models;

use App\Models\Sakemaru\User as SakemaruUser;

/**
 * Session compatibility class for Trade integration.
 *
 * Trade stores authenticated users with class name 'App\Models\User'.
 * This class enables session sharing between WMS and Trade applications.
 */
class User extends SakemaruUser
{
    // Inherits all functionality from App\Models\Sakemaru\User
}
