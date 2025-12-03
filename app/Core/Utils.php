<?php
namespace App\Core;

class Utils {

    public function log($vars) {
        if(is_resource($vars)) {
            return;
        }
        else {
            if($vars)
            {
                $json =  @json_encode($vars, JSON_PRETTY_PRINT);
                print "<script>console.log(JSON.stringify($json, undefined, 4));</script>";
            }
        }
    }

}