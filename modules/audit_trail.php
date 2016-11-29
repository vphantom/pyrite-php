<?php

/**
 * AuditTrail
 *
 * PHP version 5
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */

/**
 * AuditTrail class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class AuditTrail
{

    /**
     * Create database tables if necessary
     *
     * @return null
     */
    public static function install()
    {
        global $PPHP;
        $db = $PPHP['db'];
        echo "    Installing log... ";

        $db->begin();
        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'transactions' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                userId INTEGER NOT NULL DEFAULT '0',
                ip VARCHAR(16) NOT NULL DEFAULT '127.0.0.1',
                objectType VARCHAR(64) DEFAULT NULL,
                objectId INTEGER DEFAULT NULL,
                action VARCHAR(64) NOT NULL DEFAULT '',
                fieldName VARCHAR(64) DEFAULT NULL,
                oldValue VARCHAR(255) DEFAULT NULL,
                newValue VARCHAR(255) DEFAULT NULL
            )
            "
        );
        $db->commit();
        self::add(null, null, 'installed');
        echo "    done!\n";
    }

    /**
     * Add a new transaction to the audit trail
     *
     * Suggested minimum set of actions:
     *
     *     created
     *     modified
     *     deleted
     *
     * You can either use these positional arguments or specify a single
     * associative array argument with only the keys you need defined.
     *
     * At least an action should be specified (i.e. 'rebooted', perhaps) and
     * typically also objectType and objectId.  The rest is accessory.
     *
     * @param array|string    $objectType Class of object this applies to (*or args, see above)
     * @param string|int|null $objectId   Specific instance acted upon
     * @param string          $action     Type of action performed
     * @param string|null     $fieldName  Specific field affected
     * @param string|int|null $oldValue   Previous value for affected field
     * @param string|int|null $newValue   New value for affected field
     * @param string|int|null $userId     Over-ride session userId with this one
     *
     * @return null
     */
    public static function add($objectType, $objectId = null, $action = null, $fieldName = null, $oldValue = null, $newValue = null, $userId = 0)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $ip = '127.0.0.1';
        $req = grab('request');
        if (isset($req['remote_addr'])) {
            $ip = $req['remote_addr'];
        };

        // First argument could contain named arguments
        if (is_array($objectType)) {
            if (isset($objectType['objectId']))  $objectId  = $objectType['objectId'];
            if (isset($objectType['action']))    $action    = $objectType['action'];
            if (isset($objectType['fieldName'])) $fieldName = $objectType['fieldName'];
            if (isset($objectType['oldValue']))  $oldValue  = $objectType['oldValue'];
            if (isset($objectType['newValue']))  $newValue  = $objectType['newValue'];
            if (isset($objectType['userId']))    $userId    = $objectType['userId'];
            if (isset($objectType['objectType'])) {
                $objectType = $objectType['objectType'];
            } else {
                $objectType = null;
            };
        };

        if ($userId === 0  &&  isset($_SESSION['user']['id'])) {
            $userId = $_SESSION['user']['id'];
        };

        $db->exec(
            "
            INSERT INTO transactions
            (userId, ip, objectType, objectId, action, fieldName, oldValue, newValue)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ",
            array(
                $userId,
                $ip,
                $objectType,
                $objectId,
                $action,
                $fieldName,
                $oldValue,
                $newValue
            )
        );
    }

    /**
     * Get chronological history for a given filter
     *
     * Any supplied argument represents a restriction to apply.  At least one
     * restriction is required; it is not allowed to load the entire global
     * history at once.
     *
     * Note that 'timestamp' is in UTC and 'localtimestamp' is added to the
     * results in the server's local timezone for convenience.
     *
     * You can either use these positional arguments or specify a single
     * associative array argument with only the keys you need defined.
     *
     * @param int|null        $userId     Specific actor (*or args, see above)
     * @param array|string    $objectType Class of object this applies to
     * @param string|int|null $objectId   Specific instance acted upon
     * @param string          $action     Type of action performed
     * @param string|null     $fieldName  Specific field affected
     * @param string|null     $order      Either 'DESC' or 'ASC'
     * @param int|null        $max        LIMIT rows returned
     *
     * @return array List of associative arrays, one per entry
     */
    public static function get($userId, $objectType = null, $objectId = null, $action = null, $fieldName = null, $order = 'ASC', $max = null)
    {
        global $PPHP;
        $db = $PPHP['db'];

        $args = array();
        if (is_array($userId)) {
            $args = $userId;
            if (isset($args['order'])) {
                $order = $args['order'];
                unset($args['order']);
            };
            if (isset($args['max'])) {
                $max = $args['max'];
                unset($args['max']);
            };
        } else {
            if ($userId !== null)     $args['userId']     = $userId;
            if ($objectType !== null) $args['objectType'] = $objectType;
            if ($objectId !== null)   $args['objectId']   = $objectId;
            if ($action !== null)     $args['action']     = $action;
            if ($fieldName !== null)  $args['fieldName']  = $fieldName;
        };

        if (count($args) < 1) {
            return array();
        };

        $query = "SELECT *, datetime(timestamp, 'localtime') AS localtimestamp FROM transactions WHERE ";
        $queryArgs = array();
        $queryChunks = array();
        foreach ($args as $key => $val) {
            $queryChunks[] = "{$key}=?";
            $queryArgs[] = $val;
        };
        $query .= implode(' AND ', $queryChunks);
        $query .= " ORDER BY id {$order}";
        if ($max !== null) {
            $query .= " LIMIT {$max}";
        };

        return $db->selectArray($query, $queryArgs);
    }
}

on('install', 'AuditTrail::install');
on('log', 'AuditTrail::add');
on('history', 'AuditTrail::get');
