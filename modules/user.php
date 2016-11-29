<?php

/**
 * User
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
 * User class
 *
 * @category  Library
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */
class User
{
    private static $_selectFrom = "SELECT *, CAST((julianday('now') - julianday(onetimeTime)) * 24 * 3600 AS INTEGER) AS onetimeElapsed FROM users";

    /**
     * Create database tables if necessary
     *
     * @return null
     */
    public static function install()
    {
        global $PPHP;
        $db = $PPHP['db'];
        echo "    Installing users...\n";
        $db->begin();
        $db->exec(
            "
            CREATE TABLE IF NOT EXISTS 'users' (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                email        VARCHAR(255) NOT NULL DEFAULT '',
                passwordHash VARCHAR(255) NOT NULL DEFAULT '*',
                onetimeHash  VARCHAR(255) NOT NULL DEFAULT '*',
                onetimeTime  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                name         VARCHAR(255) NOT NULL DEFAULT ''
            )
            "
        );
        $db->exec(
            "
            CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email
            ON users (email)
            "
        );
        if (!$db->selectAtom("SELECT id FROM users WHERE id='1'")) {
            echo "Creating admin user...\n";
            $email = readline("E-mail address: ");
            $pass1 = true;
            $pass2 = false;
            while ($pass1 !== $pass2) {
                if ($pass1 !== true) {
                    echo "  * Password confirmation mis-match.\n";
                };
                $pass1 = readline("Password: ");
                $pass2 = readline("Password again: ");
            };
            $db->exec(
                "
                INSERT INTO users
                (id, email, passwordHash, name)
                VALUES
                (1, ?, ?, ?)
                ",
                array(
                    $email,
                    password_hash($pass1, PASSWORD_DEFAULT),
                    'Administrator'
                )
            );
        };
        $db->commit();
        echo "    done!\n";
    }

    /**
     * Load a user by e-mail
     *
     * @param string $email E-mail address
     *
     * @return array|bool Associative array for the user or false if not found
     */
    public static function fromEmail($email)
    {
        global $PPHP;
        $db = $PPHP['db'];
        if ($user = $db->selectSingleArray(self::$_selectFrom . " WHERE email=?", array($email))) {
            return $user;
        };
        return false;
    }

    /**
     * Load and authenticate a user
     *
     * Does not trigger any events, so it is safe to use for temporary user
     * manipulation.  Authenticating using $onetime, however, immediately
     * invalidates that password.
     *
     * @param string $email    E-mail address
     * @param string $password Plain text password (supplied via web form)
     * @param string $onetime  (Optional) Use this one-time password instead
     *
     * @return array|bool Associative array for the user or false if not authorized
     */
    public static function login($email, $password, $onetime = '')
    {
        global $PPHP;
        $db = $PPHP['db'];
        $onetimeMax = $PPHP['config']['global']['onetime_lifetime'] * 60;

        if (($user = self::fromEmail($email)) !== false) {
            if ($onetime !== '') {
                if ($user['onetimeElapsed'] < $onetimeMax  &&  password_verify($onetime, $user['onetimeHash'])) {
                    // Invalidate immediately, don't wait for expiration
                    $db->update('users', array('onetimeHash' => '*'), 'WHERE id=?', array($user['id']));
                    return $user;
                };
            } else {
                if (password_verify($password, $user['passwordHash'])) {
                    return $user;
                };
            };
        };

        return false;
    }

    /**
     * Update an existing user's information
     *
     * If an 'id' key is present in $cols, it is silently ignored.
     *
     * Special keys 'newpassword1' and 'newpassword2' trigger a re-computing
     * of 'passwordHash'.
     *
     * Special key 'onetime' triggers the creation of a new onetime token and
     * its 'onetimeHash', resets 'onetimeTime'.
     *
     * @param int   $id   ID of the user to update
     * @param array $cols Associative array of columns to update
     *
     * @return string|bool Whether it succeeded, the one time token if appropriate
     */
    public static function update($id, $cols = array())
    {
        global $PPHP;
        $db = $PPHP['db'];

        if (isset($cols['id'])) {
            unset($cols['id']);
        };

        if (isset($cols['newpassword1'])
            && strlen($cols['newpassword1']) >= 8
            && isset($cols['newpassword2'])
            && $cols['newpassword1'] === $cols['newpassword2']
        ) {
            $cols['passwordHash'] = password_hash($cols['newpassword1'], PASSWORD_DEFAULT);
            // Entries 'newpassword[12]' will be safely skipped by $db->update()
        };
        $ott = '';
        $onetime = null;
        if (isset($cols['onetime'])) {
            $ott = ", onetimeTime=datetime('now') ";
            $onetime = md5(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
            $cols['onetimeHash'] = password_hash($onetime, PASSWORD_DEFAULT);
        };
        $result = $db->update(
            'users',
            $cols,
            $ott . 'WHERE id=?',
            array($id)
        );
        if ($result) {
            trigger('user_changed', $db->selectSingleArray(self::$_selectFrom . " WHERE id=?", array($id)));
            return ($onetime !== null ? $onetime : true);
        };

        return false;
    }

    /**
     * Create new user from information
     *
     * If an 'id' key is present in $cols, it is silently ignored.
     *
     * Special key 'password' is used to create 'passwordHash', instead of
     * being inserted directly.
     *
     * If special key 'onetime' is present, it will trigger the generation of
     * a one time password, which will be returned instead of the new user ID.
     *
     * @param array $cols Associative array of columns to set
     *
     * @return string|bool|int New ID on success, false on failure
     */
    public static function create($cols = array())
    {
        global $PPHP;
        $db = $PPHP['db'];
        $onetime = null;

        if (isset($cols['id'])) {
            unset($cols['id']);
        };

        if (isset($cols['password'])) {
            $cols['passwordHash'] = password_hash($cols['password'], PASSWORD_DEFAULT);
        };
        if (isset($cols['onetime'])) {
            $onetime = md5(mcrypt_create_iv(32, MCRYPT_DEV_URANDOM));
            $cols['onetimeHash'] = password_hash($onetime, PASSWORD_DEFAULT);
        };
        if (($result = $db->insert('users', $cols)) !== false) {
            trigger('log', 'user', $result, 'created');
            trigger(
                'log',
                array(
                    'userId'     => $result,
                    'objectType' => 'user',
                    'objectId'   => $result,
                    'action'     => 'created'
                )
            );
        };
        return ($result && $onetime !== null) ? $onetime : $result;
    }
}

on('install', 'User::install');
on('user_fromemail', 'User::fromEmail');
on('authenticate', 'User::login');
on('user_update', 'User::update');
on('user_create', 'User::create');
