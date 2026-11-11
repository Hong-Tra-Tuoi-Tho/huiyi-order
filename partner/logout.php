<?php
require __DIR__.'/_common.php';
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();hpa_clear_session();session_regenerate_id(true);}
redirect(hpa_url('login.php'));
