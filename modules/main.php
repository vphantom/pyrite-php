<?php

/**
 * Main
 *
 * Main routes for our application
 *
 * PHP version 5
 *
 * @category  Application
 * @package   PyritePHP
 * @author    Stéphane Lavergne <lis@imars.com>
 * @copyright 2016 Stéphane Lavergne
 * @license   https://opensource.org/licenses/MIT  MIT
 * @link      https://github.com/vphantom/pyrite-php
 */

namespace Main;

/**
 * Enforce mandatory login for accessing a component
 *
 * @return bool Whether user is logged in and can thus proceed
 */
function isGuest()
{
    if (!$_SESSION['identified']) {
        trigger('http_status', 403);
        return true;
    };
    return false;
}

/**
 * Sanitize e-mail address
 *
 * @param string $email String to filter
 *
 * @return string
 */
function cleanEmail($email)
{
    // filter_var()'s FILTER_SANITIZE_EMAIL is way too permissive
    return preg_replace('/[^a-zA-Z0-9@.,_+-]/', '', $email);
}

/**
 * Strip low-ASCII and <>`|\"' from string
 *
 * @param string $string String to filter
 *
 * @return string
 */
function cleanText($string)
{
    return preg_replace(
        '/[<>`|\\"\']/',
        '',
        filter_var($string, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES|FILTER_FLAG_STRIP_LOW)
    );
}

on(
    'route/main',
    function () {
        if (isGuest()) return;
        if (!pass('can', 'login')) {
            return trigger('http_status', 403);
        };
        // TODO: Your application's authenticated interface starts here.
        echo "<p>Dashboard will go here</p>\n";
    }
);

on(
    'route/admin',
    function () {
        if (isGuest()) return;
        if (!pass('can', 'admin')) {
            return trigger('http_status', 403);
        };
        echo "<p>An admin dashboard can go here</p>\n";
    }
);

on(
    'route/login',
    function () {
        $req = grab('request');

        if (isset($_GET['email']) && isset($_GET['onetime'])) {

            // Account creation validation link
            if (!pass('login', $_GET['email'], null, $_GET['onetime'])) {
                return trigger('http_status', 403);
            };
        } else {

            // Normal login
            if (!pass('form_validate', 'login-form')) {
                return trigger('http_status', 440);
            };
            if (!pass('login', $_POST['email'], $_POST['password'])) {
                return trigger('http_status', 403);
            };
        };

        trigger('http_redirect', $req['base'] . '/');
    }
);

on(
    'route/logout',
    function () {
        $req = grab('request');
        trigger('logout');
        trigger('http_redirect', $req['base'] . '/');
    }
);

on(
    'route/user+prefs',
    function () {
        if (isGuest()) return;
        $saved = false;
        $success = false;

        // Settings & Information
        if (isset($_POST['name'])) {
            if (!pass('form_validate', 'user_prefs')) {
                return trigger('http_status', 440);
            };
            $saved = true;
            $_POST['name'] = cleanText($_POST['name']);
            $success = pass('user_update', $_SESSION['user']['id'], $_POST);
        };

        // Change e-mail or password
        if (isset($_POST['email'])) {
            $_POST['email'] = cleanEmail($_POST['email']);
            if (!pass('form_validate', 'user_passmail')) {
                return trigger('http_status', 440);
            };
            $saved = true;
            $oldEmail = cleanEmail($_SESSION['user']['email']);
            if (pass('login', $oldEmail, $_POST['password'])) {
                if ($success = pass('user_update', $_SESSION['user']['id'], $_POST)) {
                    $name = cleanText($_SESSION['user']['name']);
                    trigger(
                        'sendmail',
                        "{$name} <{$oldEmail}>",
                        'editaccount'
                    );
                    $newEmail = $_POST['email'];
                    if ($newEmail !== false  &&  $newEmail !== $oldEmail) {
                        trigger(
                            'sendmail',
                            "{$name} <{$newEmail}>",
                            'editaccount'
                        );
                    };
                    if ($oldEmail !== $newEmail) {
                        trigger('log', 'user', $_SESSION['user']['id'], 'modified', 'email', $oldEmail, $newEmail);
                    };
                    if (strlen($_POST['newpassword1']) >= 8) {
                        trigger('log', 'user', $_SESSION['user']['id'], 'modified', 'password');
                    };
                };
            };
        };

        trigger(
            'render',
            'user_prefs.html',
            array(
                'saved' => $saved,
                'success' => $success,
                'user' => $_SESSION['user']
            )
        );
    }
);

on(
    'route/user+history',
    function () {
        if (isGuest()) return;
        $history = grab(
            'history',
            array(
                'objectType' => 'user',
                'objectId' => $_SESSION['user']['id'],
                'order' => 'DESC',
                'max' => 20
            )
        );
        trigger(
            'render',
            'user_history.html',
            array(
                'history' => $history
            )
        );
    }
);

on(
    'route/register',
    function () {
        $created = false;
        $success = false;
        if (isset($_POST['email'])) {
            if (!pass('form_validate', 'registration')) {
                return trigger('http_status', 440);
            };
            $created = true;
            $_POST['email'] = cleanEmail($_POST['email']);
            $_POST['name'] = cleanText($_POST['name']);
            $_POST['onetime'] = true;
            if (($onetime = grab('user_create', $_POST)) !== false) {
                $success = true;
                $link = 'login?' . http_build_query(array( 'email' => $_POST['email'], 'onetime' => $onetime));
                trigger(
                    'sendmail',
                    "{$_POST['name']} <{$_POST['email']}>",
                    'confirmlink',
                    array(
                        'validation_link' => $link
                    )
                );
            } else {
                if (($user = grab('user_fromemail', $_POST['email'])) !== false) {
                    // Onetime failed because user exists, warn of duplicate
                    // attempt via e-mail, don't hint that the user exists on
                    // the web though!
                    $success = true;
                    trigger(
                        'sendmail',
                        "{$user['name']} <{$user['email']}>",
                        'duplicate'
                    );
                };
            };
        };
        trigger(
            'render',
            'register.html',
            array(
                'created' => $created,
                'success' => $success
            )
        );
    }
);

on(
    'route/password_reset',
    function () {
        $inprogress = false;
        $emailed = false;
        $saved = false;
        $valid = false;
        $success = false;
        $email = '';
        $onetime = '';

        /*
         * 1.1: Display form
         * 1.2: Using form's $email, generate one-time password and e-mail it if user is valid
         * 2.1: From e-mailed link, display password update form if one-time password checks out
         *      Generate yet another one-time password for that form, because ours expired upon verification
         * 2.2: From update form, update user's password if one-time password checks out
         *
         * This is because A) we can trust 'email' but not an ID from such a
         * public form, B) we want to keep the form tied to the user at all
         * times and C) we don't want to authenticate the user in $_SESSION at
         * this stage.
         */

        if (isset($_POST['email']) && isset($_POST['onetime']) && isset($_POST['newpassword1']) && isset($_POST['newpassword2'])) {
            // 2.2 Form submitted from a valid onetime
            $inprogress = true;
            $saved = true;
            if (($user = grab('authenticate', $_POST['email'], null, $_POST['onetime'])) !== false) {
                $success = pass(
                    'user_update',
                    $user['id'],
                    array(
                        'newpassword1' => $_POST['newpassword1'],
                        'newpassword2' => $_POST['newpassword2']
                    )
                );
            };
        } elseif (isset($_POST['email'])) {
            // 1.2 Form submitted to tell us whom to reset
            $emailed = true;
            $success = true;  // Always pretend it worked
            if (($user = grab('user_fromemail', $_POST['email'])) !== false) {
                if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) !== false) {
                    $link = 'password_reset?' . http_build_query(array( 'email' => $_POST['email'], 'onetime' => $onetime));
                    trigger(
                        'sendmail',
                        "{$user['name']} <{$_POST['email']}>",
                        'confirmlink',
                        array(
                            'validation_link' => $link
                        )
                    );
                };
            };
        } elseif (isset($_GET['email']) && isset($_GET['onetime'])) {
            // 2.1 Link from e-mail clicked, display form if onetime valid
            $inprogress = true;
            $saved = false;
            $email = cleanEmail($_GET['email']);
            if (($user = grab('authenticate', $_GET['email'], null, $_GET['onetime'])) !== false) {
                $valid = true;
                if (($onetime = grab('user_update', $user['id'], array('onetime' => true))) === false) {
                    $onetime = '';
                };
            };
        };

        trigger(
            'render',
            'password_reset.html',
            array(
                'inprogress' => $inprogress,
                'emailed'    => $emailed,
                'saved'      => $saved,
                'valid'      => $valid,
                'success'    => $success,
                'email'      => $email,
                'onetime'    => $onetime
            )
        );
    }
);


// User related routes
//

on(
    'route/admin+users',
    function ($path) {
        if (!pass('can', 'admin')) {
            return trigger('http_status', 403);
        };
        $f = array_shift($path);
        switch ($f) {

        case 'edit':
            $saved = false;
            $success = false;
            $history = array();
            $user = array();

            if (isset($_POST['name'])) {
                if (!pass('form_validate', 'user_prefs')) {
                    return trigger('http_status', 440);
                };
                $saved = true;
                $success = pass('user_update', $_POST['id'], $_POST);
            } elseif (isset($_GET['id'])) {
                $user = \Pyrite\Users::resolve($_GET['id']);
                if (!$user) {
                    // How do we say the ID is invalid?
                    return;
                };

                $user = \Pyrite\Users::fromEmail($user['email']);
                if (!$user) {
                    // Same thing...
                    return;
                };

                $history = grab(
                    'history',
                    array(
                        'objectType' => 'user',
                        'objectId' => $_GET['id'],
                        'order' => 'DESC',
                        'max' => 20
                    )
                );
            };

            trigger(
                'render',
                'admin_users_edit.html',
                array(
                    'history' => $history,
                    'user'    => $user,
                    'saved'   => $saved,
                    'success' => $success
                )
            );
            break;

        default:
            $users = \Pyrite\Users::search(
                isset($_POST['email']) && strlen($_POST['email']) > 2 ? $_POST['email'] : null,
                isset($_POST['name']) && strlen($_POST['name']) > 2 ? $_POST['name'] : null
            );
            trigger(
                'render',
                'admin_users.html',
                array(
                    'users' => $users
                )
            );

        };
    }
);
