<?php
require __DIR__.'/../lib/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST') exit;
verify_csrf();

// Back to the menu to add drinks: the Mini Game session is released only while no chance has been played.
if(hcgs_release_for_edit()){
    // The cart buttons now lead straight to checkout (see actions/game_back_checkout.php).
    $_SESSION['hcgs_adding']=true;
    redirect(base_url('index.php'));
}
// A chance was already played: the session stays, and the customer may only add drinks (never remove or change them).
if(hcgs_locked()){
    $_SESSION['hcgs_adding']=true;
    $_SESSION['hcgs_appending']=true;
    redirect(base_url('index.php'));
}
$_SESSION['hcgs_flash']=hcgs_text('add_blocked');
redirect(base_url('checkout.php'));
