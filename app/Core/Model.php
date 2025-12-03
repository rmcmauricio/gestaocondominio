<?php
namespace App\Core;

/**
 * @property \App\Core\Utils $utils
 */
class Model {

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