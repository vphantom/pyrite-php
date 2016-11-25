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
        trigger('render', 'register.html');
        return true;
    };
    return false;
}

on(
    'route/main',
    function () {
        if (!$_SESSION['identified']) {
            trigger('render', 'register.html');
            return;
        };
        if (isGuest()) return;
        // TODO: Your application's authenticated interface starts here.
        echo "<p>Dashboard will go here</p>\n";
    }
);

on(
    'route/login',
    function () {
        $req = grab('request');
        if (!pass('form_validate', 'login-form')) {
            trigger('http_status', 440);
            trigger('render', 'register.html');
            return;
        };
        if (!pass('login', $_POST['email'], $_POST['password'])) {
            trigger('http_status', 403);
            trigger('render', 'register.html', array('try_again' => true));
            return;
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
        if (isset($_POST['email'])) {
            if (!pass('form_validate', 'user_edit')) {
                trigger('http_status', 440);
                trigger('render', 'register.html');
                return;
            };
            $saved = true;
            $success = pass('user_update', $_SESSION['user']['id'], $_POST);
        };

        trigger(
            'render',
            'user_prefs.html',
            array(
                'saved' => $saved,
                'success' => $success
            )
        );
    }
);
