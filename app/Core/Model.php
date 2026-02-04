<?php
namespace App\Core;

use App\Core\Traits\Auditable;

/**
 * @property \App\Core\Utils $utils
 */
class Model {
    use Auditable;

    protected $db;
    protected Utils $utils;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        global $utils;
        $this->utils =  new Utils;
    }
}