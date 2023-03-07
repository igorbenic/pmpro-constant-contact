<?php

namespace PaidMembershipPro\EMS;

abstract class Cache {

    /**
     * Unique identifier
     *
     * @var string
     */
    protected $id = '';

    /**
     * Default lifetime of cache.
     *
     * @var float|int
     */
    protected $default_expire = DAY_IN_SECONDS;

    public function __construct( $id, $expire = null ) {
        $this->id = $id;

        if ( $expire ) {
            $this->default_expire = $expire;
        }
    }

    /**
     * Get Cache Key.
     *
     * @param string $name Cache Name.
     *
     * @return string
     */
    protected function get_cache_key( $name ) {
        return $this->id . '_' . $name;
    }

    /**
     * Get Expiration.
     *
     * @param null|integer $expire Expire timestamp. If null, it will use the default.
     *
     * @return float|int|mixed
     */
    protected function get_expiration( $expire = null ) {
        return null !== $expire ? $expire : $this->default_expire;
    }

    /**
     * Add Cache
     *
     * @param string  $name   Name of the cache.
     * @param mixed   $value  Value to cache.
     * @param integer $expire Expiration timestamp.
     *
     * @return bool
     */
    public function add( $name, $value, $expire = null ) {
        $cache_name = $this->get_cache_key( $name );

        return set_transient( $cache_name, $value, $this->get_expiration( $expire ) );
    }

    /**
     * Get Cached value.
     *
     * @param string $name Name of the cache.
     *
     * @return mixed
     */
    public function get( $name ) {
        $cache_name = $this->get_cache_key( $name );

        return get_transient( $cache_name );
    }

    /**
     * Delete transient.
     *
     * @param string $name Name of the cache.
     *
     * @return bool
     */
    public function delete( $name ) {
        $cache_name = $this->get_cache_key( $name );

        return delete_transient( $cache_name );
    }
}