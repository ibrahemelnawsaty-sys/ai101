<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Base controller.
 *
 * It brings in `authorize()` and nothing else. Controllers on this platform
 * validate through a FormRequest, authorise through a Policy, delegate to a
 * service and return a view — they hold no business rule of their own
 * (CONSTITUTION Art. 5).
 *
 * @see CONSTITUTION Art. 5, Art. 22
 */
abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;
}
