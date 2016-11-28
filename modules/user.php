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
                passwordHash VARCHAR(255) NOT NULL DEFAULT '',
                onetimeHash  VARCHAR(255) NOT NULL DEFAULT '',
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
     * Load and authenticate a user
     *
     * If the user has a one-time password set, using a regular password will
     * fail.
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

        if ($user = $db->selectSingleArray("SELECT * FROM users WHERE email=?", array($email))) {

            if ($onetime !== '') {
                if (password_verify($onetime, $user['onetimeHash'])) {
                    $db->update('users', array('onetimeHash' => ''), 'WHERE id=?', array($user['id']));
                    return $user;
                };
            } else {
                if ($user['onetimeHash'] === ''
                    &&  password_verify($password, $user['passwordHash'])
                ) {
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
     * @param int   $id   ID of the user to update
     * @param array $cols Associative array of columns to update
     *
     * @return bool Whether it succeeded
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
        $result = $db->update(
            'users',
            $cols,
            'WHERE id=?',
            array($id)
        );
        if ($result) {
            trigger('user_changed', $db->selectSingleArray("SELECT * FROM users WHERE id=?", array($id)));
            return true;
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
     * a one time password, which will be required for the user's next login
     * instead of a regular password and will be returned instead of the new
     * user ID.
     *
     * @param array $cols Associative array of columns to set
     *
     * @return bool|int New ID on success, false on failure
     */
    public static function create($cols = array())
    {
        global $PPHP;
        $db = $PPHP['db'];

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
        $result = $db->insert('users', $cols);
        return ($result && isset($cols['onetime']) ? $onetime : $result);
    }
}

on('install', 'User::install');
on('authenticate', 'User::login');
on('user_update', 'User::update');
on('user_create', 'User::create');
