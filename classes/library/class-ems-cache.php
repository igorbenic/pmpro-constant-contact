<?php

namespace PaidMembershipPro\EMS;

if ( ! class_exists( '\PaidMembershipPro\EMS\Cache' ) ) {
    require_once 'abstract/class-cache.php';
}

/**
 * Default Cache class to be used.
 *
 * If you need a custom cache layer, extend Cache and implement different methods.
 */
class EMS_Cache extends Cache {}