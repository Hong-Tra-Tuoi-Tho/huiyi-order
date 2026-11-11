<?php
require __DIR__.'/../lib/bootstrap.php';

// The customer finished adding drinks: checkout reopens the Mini Game session with the new drink count.
unset($_SESSION['hcgs_adding']);
// A session that already played keeps running (add-only): checkout grows it, it is not reopened.
$appending=!empty($_SESSION['hcgs_appending']);
unset($_SESSION['hcgs_appending']);
if(!empty($_SESSION['cart'])){
    if(!$appending)$_SESSION['hcgs_allow_open']=true;
    redirect(base_url('checkout.php'));
}
redirect(base_url('cart.php'));
